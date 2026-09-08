import { afterEach, beforeAll, beforeEach, expect, it } from 'vitest'
import { execFile, execFileSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, readFileSync, rmSync, symlinkSync, writeFileSync, existsSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { createServer, type Server, type IncomingHttpHeaders } from 'node:http'
import { chromium, type BrowserServer } from '@playwright/test'

const packageRoot = fileURLToPath(new URL('../../', import.meta.url))
const cli = join(packageRoot, 'dist/doctor/cli.js')
let root: string
let server: Server
let testingURL: string
let requests: Array<{ url: string; headers: IncomingHttpHeaders; body: string }>
let unreachable: boolean
let apiVersion: number
let refused: boolean
let sessionReady: boolean
let cleanupOutcome: string
let browserServer: BrowserServer | undefined
let databaseReady: boolean
let context: string
let rootUnreachable: boolean
let badStatus: 'preflight' | 'diagnostic' | undefined
let diagnosticReply: unknown
let loseDiagnostic: boolean

beforeAll(() => {
    execFileSync('npm', ['run', 'build'], { cwd: packageRoot, stdio: 'pipe' })
}, 30000)

beforeEach(async () => {
    requests = []
    unreachable = false
    apiVersion = 1
    refused = false
    sessionReady = true
    cleanupOutcome = 'dropped'
    databaseReady = true
    context = 'Testing'
    rootUnreachable = false
    badStatus = undefined
    diagnosticReply = undefined
    loseDiagnostic = false
    server = createServer((request, response) => {
        let body = ''
        request.on('data', (chunk: Buffer) => { body += chunk.toString() })
        request.on('end', () => {
            requests.push({ url: request.url!, headers: request.headers, body })
            if (unreachable || (rootUnreachable && request.url === '/') || (loseDiagnostic && request.headers['x-playwright-test-id'])) {
                request.socket.destroy()
                return
            }
            response.setHeader('Content-Type', 'application/json')
            if (refused) {
                response.writeHead(401).end('{"error":"Unauthorized"}')
                return
            }
            if (request.url === '/typo3/test-api/databases/drop') {
                response.end(JSON.stringify({
                    results: JSON.parse(body).testIds.map((testId: string) => ({ testId, outcome: cleanupOutcome })),
                }))
                return
            }
            if (request.headers['x-playwright-test-id'] && diagnosticReply !== undefined) {
                response.end(JSON.stringify(diagnosticReply))
                return
            }
            response.statusCode = sessionReady && databaseReady ? 200 : 503
            if (badStatus === (request.headers['x-playwright-test-id'] ? 'diagnostic' : 'preflight')) response.statusCode = 500
            response.end(JSON.stringify({ ok: sessionReady && databaseReady, api: apiVersion, checks: {
                context: { ok: true, detail: context },
                database: { ok: databaseReady, detail: databaseReady ? 'Test database ready' : 'Template missing' },
                session: { ok: sessionReady, detail: sessionReady ? 'Session ready' : 'Session unavailable' },
                media: { ok: true, detail: 'not configured' },
            } }))
        })
    })
    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve))
    const address = server.address()
    if (!address || typeof address === 'string') throw new Error('No test server port')
    testingURL = `http://127.0.0.1:${address.port}`
    root = mkdtempSync(join(tmpdir(), 'toolkit-doctor-'))
    symlinkSync(join(packageRoot, 'node_modules'), join(root, 'node_modules'), 'dir')
    writeFileSync(join(root, 'package.json'), '{"type":"module"}')
    writeFileSync(join(root, 'hooks.mjs'), `export default () => { throw new Error('Hooks must not run') }`)
    writeFileSync(join(root, 'example.spec.ts'), `
        import { test } from '@playwright/test'
        test('must not run', () => { throw new Error('Tests must not run') })
    `)
    writeFileSync(join(root, 'playwright.config.ts'), `
        import { defineConfig } from '@playwright/test'
        import { defineToolkitConfig } from '${pathToFileURL(join(packageRoot, 'dist/config.js')).href}'
        defineToolkitConfig({
            testingURL: '${testingURL}',
            paths: { consumerRoot: ${JSON.stringify(root)} },
            build: 'exit 99',
        })
        export default defineConfig({
            globalSetup: './hooks.mjs', globalTeardown: './hooks.mjs',
            webServer: { command: 'exit 99', port: 12345 },
            use: { launchOptions: { executablePath: '/missing-doctor-browser' } },
        })
    `)
})

afterEach(async () => {
    await browserServer?.close()
    browserServer = undefined
    server.closeAllConnections()
    await new Promise<void>((resolve) => server.close(() => resolve()))
    rmSync(root, { recursive: true, force: true })
})

function doctor(args: string[] = [], env: NodeJS.ProcessEnv = {}): Promise<{ code: number; output: string }> {
    return new Promise((resolve) => {
        execFile(process.execPath, [cli, ...args], { cwd: root, timeout: 15000, env: { ...process.env, ...env } }, (error, stdout, stderr) => {
            resolve({ code: error ? Number(error.code) || 1 : 0, output: stdout + stderr })
        })
    })
}

it('loads the real config and reports a missing browser without running hooks or tests', async () => {
    const result = await doctor()

    expect(result.code).toBe(1)
    expect(result.output).toContain('✓ Playwright config loaded:')
    expect(result.output).toContain('✗ Browser [chromium]')
    expect(result.output).toContain('/missing-doctor-browser')
    expect(result.output).not.toContain('must not run')
    expect(existsSync(join(root, '.test-state'))).toBe(false)
    expect(existsSync(join(root, 'test-results'))).toBe(false)
})

it('names a config that never called defineToolkitConfig instead of blaming its browsers', async () => {
    writeFileSync(join(root, 'playwright.config.ts'), `
        import { defineConfig } from '@playwright/test'
        export default defineConfig({ projects: [{ name: 'desktop' }, { name: 'mobile' }] })
    `)

    const result = await doctor()

    expect(result.code).toBe(1)
    expect(result.output).toContain('✗ Toolkit configuration')
    expect(result.output).toContain('defineToolkitConfig')
    expect(result.output).not.toContain('[desktop]')
    expect(result.output).not.toContain('[mobile]')
})

it.each(['--reporter=line', '--ui', '--update-snapshots', '--skip-prepare'])('rejects unrelated test-runner flag %s', async (flag) => {
    const result = await doctor([flag])

    expect(result.code).toBe(1)
    expect(result.output).toContain('Unknown option')
})

it('checks the API and session, then drops only its own diagnostic database', async () => {
    const file = join(root, 'playwright.config.ts')
    writeFileSync(file, readFileSync(file, 'utf8').replace("executablePath: '/missing-doctor-browser'", ''))

    const result = await doctor()

    expect(result.output).toContain('✓ API secret accepted')
    expect(result.output).toContain('✓ Toolkit API versions compatible')
    expect(result.output).toContain('✓ Test database created from template')
    expect(result.output).toContain('✓ Backend session usable')
    expect(result.output).toContain('✓ Media fixtures seeded')
    expect(result.output).toContain('✓ Diagnostic database removed')
    expect(result.output).toContain('Ready to run tests.')
    expect(result.code).toBe(0)
    const probes = requests.filter((request) => request.url.endsWith('/health'))
    expect(probes).toHaveLength(2)
    expect(probes[0].headers['x-playwright-test-id']).toBeUndefined()
    const testId = probes[1].headers['x-playwright-test-id']
    expect(testId).toMatch(/^[A-Z0-9]{16}$/)
    const drops = requests.filter((request) => request.url.endsWith('/drop'))
    expect(drops).toHaveLength(1)
    expect(JSON.parse(drops[0].body)).toEqual({ testIds: [testId] })
    expect(drops[0].headers['x-playwright-test-id']).toBeUndefined()
    expect(existsSync(join(root, '.test-state'))).toBe(false)
})

it('skips dependent checks when the testing URL cannot answer', async () => {
    unreachable = true
    const result = await doctor()

    expect(result.code).toBe(1)
    expect(result.output).toContain('✗ Testing URL')
    expect(result.output).toContain('– API authentication skipped')
    expect(result.output).toContain('– Database and session checks skipped')
    expect(requests.every((request) => !request.headers['x-playwright-test-id'])).toBe(true)
    expect(requests.some((request) => request.url.endsWith('/drop'))).toBe(false)
})

it('refuses an old API before creating a database it could not clean up', async () => {
    apiVersion = 0
    const result = await doctor()

    expect(result.code).toBe(1)
    expect(result.output).toContain('composer update plan2net/playwright-toolkit')
    expect(result.output).toContain('– Database and session checks skipped')
    expect(requests.every((request) => !request.headers['x-playwright-test-id'])).toBe(true)
    expect(requests.some((request) => request.url.endsWith('/drop'))).toBe(false)
})

it('reports authentication failure without blaming the API version or creating a database', async () => {
    refused = true
    const result = await doctor()

    expect(result.code).toBe(1)
    expect(result.output).toContain('✗ API secret')
    expect(result.output).toContain('– API compatibility skipped')
    expect(result.output).toContain('– Database and session checks skipped')
    expect(result.output).not.toContain('composer update')
    expect(requests.every((request) => !request.headers['x-playwright-test-id'])).toBe(true)
})

it('still removes the diagnostic database after a failed session check', async () => {
    sessionReady = false
    const result = await doctor()

    expect(result.code).toBe(1)
    expect(result.output).toContain('Session unavailable')
    expect(result.output).toContain('ddev playwright prepare --force')
    expect(result.output).toContain('✓ Diagnostic database removed')
    expect(requests.filter((request) => request.url.endsWith('/drop'))).toHaveLength(1)
})

it('reports the database ID when cleanup fails', async () => {
    cleanupOutcome = 'failed'
    const result = await doctor()

    expect(result.code).toBe(1)
    expect(result.output).toMatch(/✗ Diagnostic database cleanup: db[A-Z0-9]{16}/)
    expect(result.output).not.toContain('✓ Diagnostic database removed')
    expect(result.output).not.toContain('Ready to run tests.')
})

it('connects to the configured remote browser instead of launching one locally', async () => {
    browserServer = await chromium.launchServer()
    const file = join(root, 'playwright.config.ts')
    writeFileSync(file, readFileSync(file, 'utf8').replace('use: {',
        `use: { connectOptions: { wsEndpoint: ${JSON.stringify(browserServer.wsEndpoint())} },`))

    const result = await doctor()

    expect(result.output).toContain('✓ Browser [chromium] connected')
    expect(result.output).not.toContain('/missing-doctor-browser')
    expect(result.code).toBe(0)
})

it('honours the remote-browser environment override used by Playwright tests', async () => {
    browserServer = await chromium.launchServer()
    const result = await doctor([], {
        PW_TEST_CONNECT_WS_ENDPOINT: browserServer.wsEndpoint(),
        PW_TEST_CONNECT_HEADERS: '{"X-Doctor-Test":"yes"}',
    })

    expect(result.output).toContain('✓ Browser [chromium] connected')
    expect(result.code).toBe(0)
})

it('uses the selected project and its browser channel from a custom config', async () => {
    const file = join(root, 'custom.config.ts')
    writeFileSync(file, readFileSync(join(root, 'playwright.config.ts'), 'utf8').replace(
        "use: { launchOptions: { executablePath: '/missing-doctor-browser' } },",
        `projects: [
            { name: 'desktop', use: { channel: 'not-a-browser-channel' } },
            { name: 'mobile', use: { launchOptions: { executablePath: '/unselected-browser' } } },
        ],`,
    ))

    const result = await doctor(['--config', 'custom.config.ts', '--project', 'desktop'])

    expect(result.code).toBe(1)
    expect(result.output).toContain('custom.config.ts')
    expect(result.output).toContain('not-a-browser-channel')
    expect(result.output).not.toContain('[mobile]')
})

it('checks browsers even before the first test file exists', async () => {
    rmSync(join(root, 'example.spec.ts'))
    const result = await doctor()

    expect(result.output).toContain('✗ Browser [chromium]')
    expect(result.code).toBe(1)
})

it('skips the session check when the diagnostic database is unavailable', async () => {
    databaseReady = false
    const result = await doctor()

    expect(result.code).toBe(1)
    expect(result.output).toContain('Template missing')
    expect(result.output).toContain('– Backend session check skipped')
    expect(result.output).not.toContain('✓ Backend session usable')
    expect(result.output).toContain('✓ Diagnostic database removed')
})

it('refuses a non-Testing context before sending a test ID', async () => {
    context = 'Production'
    const result = await doctor()

    expect(result.code).toBe(1)
    expect(result.output).toContain('✗ Testing context')
    expect(result.output).toContain('TYPO3_CONTEXT=Testing')
    expect(requests.every((request) => !request.headers['x-playwright-test-id'])).toBe(true)
})

it('checks that the browser itself can reach the testing site', async () => {
    rootUnreachable = true
    const file = join(root, 'playwright.config.ts')
    writeFileSync(file, readFileSync(file, 'utf8').replace("executablePath: '/missing-doctor-browser'", ''))
    const result = await doctor()

    expect(result.output).toContain('✓ Browser [chromium] launched')
    expect(result.output).toContain('✗ Browser access [chromium]')
    expect(result.output).toContain('✓ API secret accepted')
    expect(result.code).toBe(1)
})

it.each(['preflight', 'diagnostic'] as const)('rejects an HTTP error during %s even with a healthy-looking body', async (stage) => {
    badStatus = stage
    const result = await doctor()

    expect(result.code).toBe(1)
    expect(result.output).toContain('HTTP 500')
    expect(result.output).not.toContain('✓ Test database created from template')
    expect(requests.filter((request) => request.url.endsWith('/drop'))).toHaveLength(stage === 'diagnostic' ? 1 : 0)
})

it('names a missing API secret and skips checks that need it', async () => {
    const result = await doctor([], { PLAYWRIGHT_TOOLKIT_SECRET: '' })

    expect(result.code).toBe(1)
    expect(result.output).toContain('✗ API secret')
    expect(result.output).toContain('– Database and session checks skipped')
    expect(requests).toHaveLength(0)
})

it.each([
    null,
    { ok: true, checks: {} },
    { ok: false, checks: {
        context: { ok: true, detail: 'Testing' },
        database: { ok: true }, session: { ok: true },
    } },
])('refuses incomplete or inconsistent health results: %j', async (reply) => {
    diagnosticReply = reply
    const file = join(root, 'playwright.config.ts')
    writeFileSync(file, readFileSync(file, 'utf8').replace("executablePath: '/missing-doctor-browser'", ''))
    const result = await doctor()

    expect(result.code).toBe(1)
    expect(result.output).not.toContain('Ready to run tests.')
    expect(result.output).toContain('✓ Diagnostic database removed')
})

it('attempts cleanup when the diagnostic response is lost', async () => {
    loseDiagnostic = true
    const result = await doctor()

    expect(result.code).toBe(1)
    expect(result.output).toContain('Diagnostic request failed')
    expect(result.output).toContain('✓ Diagnostic database removed')
    expect(requests.filter((request) => request.url.endsWith('/drop'))).toHaveLength(1)
})

it('leaves previous run state intact and ignores replay and cleanup skip settings', async () => {
    mkdirSync(join(root, 'test-results'))
    const lastRun = join(root, 'test-results/.last-run.json')
    writeFileSync(lastRun, '{"status":"failed","failedTests":["previous-test"]}')
    mkdirSync(join(root, '.test-state/runs/previous'), { recursive: true })
    const attempts = join(root, '.test-state/runs/previous/attempts.jsonl')
    writeFileSync(attempts, '{"testId":"KEPT000000000000"}\n')

    await doctor([], { PW_REPLAY: '1', NO_DATABASE_CLEANUP: '1', PW_SKIP_HEALTH: '1' })

    expect(readFileSync(lastRun, 'utf8')).toBe('{"status":"failed","failedTests":["previous-test"]}')
    expect(readFileSync(attempts, 'utf8')).toBe('{"testId":"KEPT000000000000"}\n')
    const drop = requests.find((request) => request.url.endsWith('/drop'))!
    expect(drop.body).not.toContain('REPLAY0000000000')
    expect(drop.body).not.toContain('KEPT000000000000')
    expect(requests.some((request) => request.url.endsWith('/sweep'))).toBe(false)
})

it('shows help without loading a config or opening a browser', async () => {
    rmSync(join(root, 'playwright.config.ts'))
    const result = await doctor(['--help'])

    expect(result.code).toBe(0)
    expect(result.output).toContain('Usage: typo3-playwright-doctor')
    expect(requests).toHaveLength(0)
})

it('keeps toolkit identity headers off browser requests before the API safety checks', async () => {
    const file = join(root, 'playwright.config.ts')
    writeFileSync(file, readFileSync(file, 'utf8').replace(
        "use: { launchOptions: { executablePath: '/missing-doctor-browser' } },",
        `use: { extraHTTPHeaders: {
            'X-Playwright-Test-Id': 'KEPT000000000000',
            'X-Playwright-Toolkit-Secret': 'do-not-send-to-browser',
            'X-Doctor-Test': 'preserved',
        } },`,
    ))

    const result = await doctor()

    expect(result.code).toBe(0)
    const navigation = requests.find((request) => request.url === '/')!
    expect(navigation.headers['x-doctor-test']).toBe('preserved')
    expect(navigation.headers['x-playwright-test-id']).toBeUndefined()
    expect(navigation.headers['x-playwright-toolkit-secret']).toBeUndefined()
})
