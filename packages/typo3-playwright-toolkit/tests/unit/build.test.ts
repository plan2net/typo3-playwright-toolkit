import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import { resolveBuildCommand, runBuild } from '#src/build.js'
import type { ToolkitConfig } from '#src/config.js'

let root: string

function configFor(build?: string | false): ToolkitConfig {
    return {
        testingURL: 'https://example-testing.test',
        build,
        paths: {
            consumerRoot: root,
            stateDir: path.join(root, '.test-state'),
            sessionDir: path.join(root, 'var/session'),
        },
    }
}

function writeManifest(scripts: Record<string, string>): void {
    fs.writeFileSync(path.join(root, 'package.json'), JSON.stringify({ name: 'consumer', scripts }))
}

beforeEach(() => {
    root = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-build-'))
    delete process.env.PW_SKIP_BUILD
})

afterEach(() => {
    fs.rmSync(root, { recursive: true, force: true })
    delete process.env.PW_SKIP_BUILD
})

describe('resolveBuildCommand', () => {
    it('builds nothing in a project without a package.json', () => {
        expect(resolveBuildCommand(configFor())).toBeUndefined()
    })

    // No build script means a project that never builds. A package.json nobody can
    // parse is a broken one, and treating it the same way runs the suite on stale
    // assets and writes them as the next baseline.
    it('refuses a package.json it cannot parse', () => {
        fs.writeFileSync(path.join(root, 'package.json'), '{ "scripts": { "build": "vite" },}')

        expect(() => resolveBuildCommand(configFor())).toThrow(/package\.json[\s\S]*build: false/)
    })

    it('runs the build script through npm when no lockfile names another manager', () => {
        writeManifest({ build: 'vite build' })

        expect(resolveBuildCommand(configFor())).toBe('npm run build')
    })

    it.each([
        ['pnpm-lock.yaml', 'pnpm run build'],
        ['yarn.lock', 'yarn run build'],
        ['bun.lockb', 'bun run build'],
        ['bun.lock', 'bun run build'],
        ['package-lock.json', 'npm run build'],
    ])('takes the package manager from %s', (lockfile, expected) => {
        writeManifest({ build: 'vite build' })
        fs.writeFileSync(path.join(root, lockfile), '')

        expect(resolveBuildCommand(configFor())).toBe(expected)
    })

    it('prefers a configured command over anything detected', () => {
        writeManifest({ build: 'vite build' })

        expect(resolveBuildCommand(configFor('make assets'))).toBe('make assets')
    })

    it('builds nothing when the config turns the build off', () => {
        writeManifest({ build: 'vite build' })

        expect(resolveBuildCommand(configFor(false))).toBeUndefined()
    })
})

describe('runBuild', () => {
    it('runs the configured command in the consumer root', () => {
        runBuild(configFor('printf ran > built.txt'))

        expect(fs.readFileSync(path.join(root, 'built.txt'), 'utf8')).toBe('ran')
    })

    it('fails the run when the build fails, naming the way out', () => {
        expect(() => runBuild(configFor('exit 3'))).toThrow(/exit 3[\s\S]*PW_SKIP_BUILD=1/)
    })

    // Outside DDEV there is no --skip-build flag, so this is the only way to skip.
    it('skips the build when PW_SKIP_BUILD=1', () => {
        process.env.PW_SKIP_BUILD = '1'

        runBuild(configFor('printf ran > built.txt'))

        expect(fs.existsSync(path.join(root, 'built.txt'))).toBe(false)
    })
})
