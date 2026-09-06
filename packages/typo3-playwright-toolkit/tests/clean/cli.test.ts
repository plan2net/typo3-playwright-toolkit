import { afterEach, beforeAll, beforeEach, expect, it } from 'vitest'
import { execFile, execFileSync } from 'node:child_process'
import { existsSync, mkdirSync, mkdtempSync, rmSync, utimesSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createServer, type IncomingHttpHeaders, type Server } from 'node:http'

const packageRoot = fileURLToPath(new URL('../../', import.meta.url))
const cli = join(packageRoot, 'dist/clean/cli.js')
const SECRET = 'clean-test-secret'

let root: string
let server: Server
let testingURL: string
let requests: Array<{ url: string; headers: IncomingHttpHeaders; body: string }>

beforeAll(() => {
    execFileSync('npm', ['run', 'build'], { cwd: packageRoot, stdio: 'pipe' })
}, 60000)

beforeEach(async () => {
    requests = []
    server = createServer((request, response) => {
        let body = ''
        request.on('data', (chunk: Buffer) => { body += chunk.toString() })
        request.on('end', () => {
            requests.push({ url: request.url!, headers: request.headers, body })
            response.setHeader('Content-Type', 'application/json')
            if (request.url?.endsWith('/drop')) {
                response.end(JSON.stringify({
                    results: (JSON.parse(body) as { testIds: string[] }).testIds
                        .map((testId) => ({ testId, outcome: 'dropped' })),
                }))

                return
            }
            response.end(JSON.stringify({ results: [], kept: 0, cutoffMs: 0 }))
        })
    })
    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve))
    const address = server.address()
    if (!address || typeof address === 'string') throw new Error('No test server port')
    testingURL = `http://127.0.0.1:${address.port}`
    root = mkdtempSync(join(tmpdir(), 'toolkit-clean-'))
})

afterEach(async () => {
    server.closeAllConnections()
    await new Promise<void>((resolve) => server.close(() => resolve()))
    rmSync(root, { recursive: true, force: true })
})

function writeRun(runId: string, testIds: string[], lastActiveMs: number): string {
    const runDir = join(root, '.test-state/runs', runId)
    mkdirSync(runDir, { recursive: true })
    writeFileSync(join(runDir, 'meta.json'), JSON.stringify({ testingURL }))
    writeFileSync(
        join(runDir, 'attempts.jsonl'),
        testIds.map((testId) => JSON.stringify({
            type: 'attempt', key: 'scenario', name: 'scenario', attempt: 1, testId,
            nonce: 'n', startedAt: new Date(lastActiveMs).toISOString(),
        })).join('\n') + '\n',
    )
    writeFileSync(join(runDir, 'liveness'), String(lastActiveMs))
    const seconds = lastActiveMs / 1000
    for (const entry of ['liveness', 'attempts.jsonl', 'meta.json', '']) {
        utimesSync(join(runDir, entry), seconds, seconds)
    }

    return runDir
}

function clean(args: string[] = []): Promise<{ code: number; output: string }> {
    return new Promise((resolve) => {
        execFile(process.execPath, [cli, ...args], {
            cwd: root,
            timeout: 20000,
            env: { ...process.env, PLAYWRIGHT_TOOLKIT_SECRET: SECRET },
        }, (error, stdout, stderr) => {
            resolve({ code: error ? Number(error.code) || 1 : 0, output: stdout + stderr })
        })
    })
}

it('says there is nothing to clean when no run was ever recorded', async () => {
    const result = await clean()

    expect(result.code).toBe(0)
    expect(result.output).toContain('Nothing to clean')
    expect(requests).toHaveLength(0)
})

it('leaves a run that is still going, and tells the sweep to keep its databases', async () => {
    const running = writeRun('running-run', ['BBBBBBBBBBBBBBBB'], Date.now())
    writeRun('abandoned-run', ['AAAAAAAAAAAAAAAA'], Date.now() - 3_600_000)

    const result = await clean()

    expect(result.code).toBe(0)
    const drop = requests.find((request) => request.url.endsWith('/drop'))!
    expect(JSON.parse(drop.body)).toEqual({ testIds: ['AAAAAAAAAAAAAAAA'] })
    const sweep = requests.find((request) => request.url.endsWith('/sweep'))!
    expect((JSON.parse(sweep.body) as { keepTestIds: string[] }).keepTestIds).toContain('BBBBBBBBBBBBBBBB')
    expect(existsSync(running)).toBe(true)
})

it('drops the databases an abandoned run recorded and removes its run directory', async () => {
    const runDir = writeRun('abandoned-run', ['AAAAAAAAAAAAAAAA'], Date.now() - 3_600_000)

    const result = await clean()

    expect(result.code).toBe(0)
    const drop = requests.find((request) => request.url.endsWith('/drop'))!
    expect(JSON.parse(drop.body)).toEqual({ testIds: ['AAAAAAAAAAAAAAAA'] })
    expect(drop.headers['x-playwright-toolkit-secret']).toBe(SECRET)
    expect(existsSync(runDir)).toBe(false)
})
