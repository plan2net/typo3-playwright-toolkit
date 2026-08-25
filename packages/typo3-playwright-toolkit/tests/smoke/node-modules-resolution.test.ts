import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { execFileSync } from 'child_process'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import { fileURLToPath, pathToFileURL } from 'url'

const here = path.dirname(fileURLToPath(import.meta.url))
const packageRoot = path.resolve(here, '../..')

let consumerDir: string
let installedEntry: string

beforeAll(() => {
    // Deliberately no build here: packing must produce a usable tarball on its
    // own, or `npm publish` from a clean checkout ships a package without dist.
    fs.rmSync(path.join(packageRoot, 'dist'), { recursive: true, force: true })

    const packOutput = execFileSync('npm', ['pack', '--json'], { cwd: packageRoot }).toString()
    const tarball = path.join(packageRoot, JSON.parse(packOutput)[0].filename)

    consumerDir = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-consumer-'))
    fs.writeFileSync(
        path.join(consumerDir, 'package.json'),
        JSON.stringify({ name: 'consumer', version: '1.0.0', type: 'module', private: true }),
    )
    execFileSync('npm', ['install', tarball, '@playwright/test@^1.56.0'], { cwd: consumerDir, stdio: 'inherit' })

    installedEntry = path.join(
        consumerDir,
        'node_modules/@plan2net/typo3-playwright-toolkit/dist/global-teardown.js',
    )
    fs.rmSync(tarball, { force: true })
})

afterAll(() => {
    if (consumerDir) {
        fs.rmSync(consumerDir, { recursive: true, force: true })
    }
})

describe('package resolved from node_modules', () => {
    it('exposes global-teardown at the installed subpath', () => {
        expect(fs.existsSync(installedEntry)).toBe(true)
    })

    it('runs runTeardown from the installed package against a temp filesystem', async () => {
        const mod = await import(pathToFileURL(installedEntry).href)
        const root = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-installed-root-'))
        const stateDir = path.join(root, '.test-state')
        const runDir = path.join(stateDir, 'runs', process.env.PW_RUN_ID ?? 'packsmoke0000')
        fs.mkdirSync(runDir, { recursive: true })

        await mod.runTeardown(
            {
                testingURL: 'https://example-testing.test',
                contentTypes: {},
                runId: 'packsmoke0000',
                paths: {
                    consumerRoot: root,
                    stateDir,
                    sessionDir: path.join(root, 'var/session'),
                },
            },
            {
                // The installed package must accept an injected client, which is
                // also how a consumer would test their own teardown.
                cleanup: {
                    drop: async () => [],
                    sweep: async () => ({ results: [], kept: 0, cutoffMs: 0, unreachable: false }),
                },
                preserve: { mode: 'none' },
            },
        )

        expect(fs.existsSync(runDir)).toBe(false)
        fs.rmSync(root, { recursive: true, force: true })
    })

    // The exports map decides what the documented `/playwright` import resolves
    // to, and pointing it at the wrong module is invisible from src/.
    it('exposes the documented playwright subpath with both config helpers', async () => {
        const mod = await import(
            path.join(consumerDir, 'node_modules/@plan2net/typo3-playwright-toolkit/dist/playwright/index.js')
        )

        expect(typeof mod.defineToolkitConfig).toBe('function')
        expect(typeof mod.defineBasePlaywrightConfig).toBe('function')
    })

    // axe-core is resolved relative to the installed package, so this is the one
    // place the resolution can break: it works in src/ and fails from node_modules.
    it('reads axe-core from the installed package', async () => {
        const mod = await import(
            path.join(consumerDir, 'node_modules/@plan2net/typo3-playwright-toolkit/dist/checks/accessibility.js')
        )

        const source = mod.axeSource()

        expect(source.length).toBeGreaterThan(100_000)
        expect(source).toContain('axe')
    })

    // Without NodeNext resolution the exports map is invisible to TypeScript and
    // every fixture types as `any`, which is what a consumer sees with no tsconfig.
    it('type-checks a consumer test that extends the shipped tsconfig', () => {
        fs.writeFileSync(
            path.join(consumerDir, 'tsconfig.json'),
            JSON.stringify({
                extends: '@plan2net/typo3-playwright-toolkit/tsconfig.base.json',
                include: ['*.ts'],
            }),
        )
        fs.writeFileSync(
            path.join(consumerDir, 'probe.ts'),
            "import { definePair, expect } from '@plan2net/typo3-playwright-toolkit'\n" +
                'const test = definePair(async () => ({ slug: "/x" }))\n' +
                "test('typed', async ({ page, state }) => {\n" +
                '    await page.goto(state.slug)\n' +
                '    await expect(page.locator("h1")).toBeVisible()\n' +
                '})\n',
        )

        execFileSync('npm', ['install', 'typescript@^5'], { cwd: consumerDir, stdio: 'inherit' })

        expect(() =>
            execFileSync('npx', ['tsc', '--noEmit'], { cwd: consumerDir, stdio: 'pipe' }),
        ).not.toThrow()
    })

    it('can be required from a CommonJS consumer', () => {
        const probe = path.join(consumerDir, 'probe.cjs')
        fs.writeFileSync(
            probe,
            "const toolkit = require('@plan2net/typo3-playwright-toolkit')\n" +
                'process.stdout.write(typeof toolkit.definePair)\n',
        )

        const resolved = execFileSync('node', [probe], { cwd: consumerDir }).toString()

        expect(resolved).toBe('function')
    })
})
