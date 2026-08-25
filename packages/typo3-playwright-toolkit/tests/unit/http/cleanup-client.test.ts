import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import type { ToolkitConfig } from '#src/config.js'
import { MAXIMUM_BATCH, httpCleanup } from '#src/http/cleanup-client.js'
import { TEST_ID_HEADER } from '#src/contract.js'
import { SECRET_HEADER } from '#src/http/api-secret.js'

let tmpRoot: string
let config: ToolkitConfig

function configFor(root: string): ToolkitConfig {
    return {
        testingURL: 'https://example-testing.test',
        contentTypes: {},
        paths: {
            consumerRoot: root,
            stateDir: path.join(root, '.test-state'),
            sessionDir: path.join(root, 'var/session'),
        },
        runId: 'aaaaaaaaaaaaaaaa',
    }
}

interface Call {
    url: string
    method?: string
    headers: Record<string, string>
    body: unknown
}

function recorder(respond: (body: unknown) => Response): { calls: Call[]; fetchImpl: typeof fetch } {
    const calls: Call[] = []

    return {
        calls,
        fetchImpl: (async (url: string, init: { method?: string; headers?: Record<string, string>; body?: string }) => {
            const body = init?.body ? JSON.parse(init.body) : undefined
            calls.push({ url, method: init?.method, headers: init?.headers ?? {}, body })
            return respond(body)
        }) as unknown as typeof fetch,
    }
}

function ok(body: Record<string, unknown>): Response {
    return new Response(JSON.stringify({ ok: true, ...body }), { status: 200 })
}

beforeEach(() => {
    tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-cleanup-'))
    config = configFor(tmpRoot)
})

afterEach(() => {
    fs.rmSync(tmpRoot, { recursive: true, force: true })
})

describe('httpCleanup.drop', () => {
    it('posts the ids to the drop endpoint', async () => {
        const { calls, fetchImpl } = recorder(() => ok({ results: [] }))

        await httpCleanup(config, { fetchImpl }).drop(['ABCD1234EFGH5678'])

        expect(calls[0].url).toBe('https://example-testing.test/typo3/test-api/databases/drop')
        expect(calls[0].method).toBe('POST')
        expect(calls[0].body).toEqual({ testIds: ['ABCD1234EFGH5678'] })
    })

    // A drop request carrying a test-ID header would make the extension provision
    // the database on the way in and then drop what it just created.
    it('sends no test id header', async () => {
        const { calls, fetchImpl } = recorder(() => ok({ results: [] }))

        await httpCleanup(config, { fetchImpl }).drop(['ABCD1234EFGH5678'])

        expect(calls[0].headers[TEST_ID_HEADER]).toBeUndefined()
    })

    // Without it the endpoint answers 401 and the run leaks every database it made.
    it('sends the api secret', async () => {
        const { calls, fetchImpl } = recorder(() => ok({ results: [] }))

        await httpCleanup(config, { fetchImpl }).drop(['ABCD1234EFGH5678'])

        expect(calls[0].headers[SECRET_HEADER]).toBe(process.env.PLAYWRIGHT_TOOLKIT_SECRET)
    })

    it('returns the per-database outcomes the endpoint reported', async () => {
        const { fetchImpl } = recorder(() =>
            ok({
                results: [
                    { testId: 'AAAA1111AAAA1111', outcome: 'dropped' },
                    { testId: 'BBBB2222BBBB2222', outcome: 'unclaimed' },
                ],
            }),
        )

        const results = await httpCleanup(config, { fetchImpl }).drop([
            'AAAA1111AAAA1111',
            'BBBB2222BBBB2222',
        ])

        expect(results).toEqual([
            { testId: 'AAAA1111AAAA1111', outcome: 'dropped' },
            { testId: 'BBBB2222BBBB2222', outcome: 'unclaimed' },
        ])
    })

    it('asks for nothing when there is nothing to drop', async () => {
        const { calls, fetchImpl } = recorder(() => ok({ results: [] }))

        expect(await httpCleanup(config, { fetchImpl }).drop([])).toEqual([])
        expect(calls).toEqual([])
    })

    // An unreachable site must not look like a successful cleanup, or the run
    // registry is discarded and the databases leak silently.
    it('reports every id as failed when the endpoint is unreachable', async () => {
        const fetchImpl = (async () => {
            throw new Error('ECONNREFUSED')
        }) as unknown as typeof fetch

        const results = await httpCleanup(config, { fetchImpl }).drop(['ABCD1234EFGH5678'])

        expect(results).toEqual([{ testId: 'ABCD1234EFGH5678', outcome: 'failed' }])
    })

    it('reports failed when the endpoint answers with an error status', async () => {
        const fetchImpl = (async () => new Response('nope', { status: 404 })) as unknown as typeof fetch

        const results = await httpCleanup(config, { fetchImpl }).drop(['ABCD1234EFGH5678'])

        expect(results).toEqual([{ testId: 'ABCD1234EFGH5678', outcome: 'failed' }])
    })

    it('reports failed for an id the endpoint answered nothing about', async () => {
        const { fetchImpl } = recorder(() => ok({ results: [] }))

        const results = await httpCleanup(config, { fetchImpl }).drop(['ABCD1234EFGH5678'])

        expect(results).toEqual([{ testId: 'ABCD1234EFGH5678', outcome: 'failed' }])
    })

    // The endpoint refuses an over-long batch, so a big run has to be chunked or
    // its whole cleanup fails at once.
    it('splits a batch larger than the endpoint accepts', async () => {
        const { calls, fetchImpl } = recorder((body) =>
            ok({
                results: ((body as { testIds: string[] }).testIds ?? []).map((testId) => ({
                    testId,
                    outcome: 'dropped',
                })),
            }),
        )
        const many = Array.from({ length: MAXIMUM_BATCH + 5 }, (_, index) =>
            `T${String(index).padStart(15, '0')}`,
        )

        const results = await httpCleanup(config, { fetchImpl }).drop(many)

        expect(calls).toHaveLength(2)
        expect((calls[0].body as { testIds: string[] }).testIds).toHaveLength(MAXIMUM_BATCH)
        expect((calls[1].body as { testIds: string[] }).testIds).toHaveLength(5)
        expect(results).toHaveLength(MAXIMUM_BATCH + 5)
    })
})

// The two packages have separate test suites and can drift apart without either
// noticing. These read the same fixture the extension asserts its real response
// against, so a wire-shape change fails on both sides.
describe('the contract fixtures', () => {
    function fixture(name: string): Record<string, unknown> {
        const repoRoot = path.resolve(new URL(import.meta.url).pathname, '../../../../../..')
        const body = JSON.parse(fs.readFileSync(path.join(repoRoot, 'contract', `${name}.json`), 'utf8'))
        delete body._comment

        return body
    }

    it('parses the drop response the extension actually returns', async () => {
        const { fetchImpl } = recorder(() => new Response(JSON.stringify(fixture('cleanup-drop-response'))))

        const results = await httpCleanup(config, { fetchImpl }).drop([
            'AAAA1111AAAA1111',
            'BBBB2222BBBB2222',
            'CCCC3333CCCC3333',
            'not-a-test-id',
        ])

        expect(results).toEqual([
            { testId: 'AAAA1111AAAA1111', outcome: 'dropped' },
            { testId: 'BBBB2222BBBB2222', outcome: 'unclaimed' },
            { testId: 'CCCC3333CCCC3333', outcome: 'absent' },
            { testId: 'not-a-test-id', outcome: 'refused' },
        ])
    })

    it('parses the sweep response the extension actually returns', async () => {
        const { fetchImpl } = recorder(() => new Response(JSON.stringify(fixture('cleanup-sweep-response'))))

        const sweep = await httpCleanup(config, { fetchImpl }).sweep([], 1000)

        expect(sweep.results).toEqual([{ testId: 'OLDOLD11OLDOLD11', outcome: 'dropped' }])
        expect(sweep.kept).toBe(2)
        expect(sweep.cutoffMs).toBe(3600000)
        expect(sweep.unreachable).toBe(false)
    })
})

// Teardown discards the run registry on a terminal outcome, so anything it cannot
// recognise has to read as failed rather than as "nothing left to do".
describe('httpCleanup — untrusted responses', () => {
    it('treats an unknown outcome as failed', async () => {
        const { fetchImpl } = recorder(() =>
            ok({ results: [{ testId: 'ABCD1234EFGH5678', outcome: 'vaporised' }] }),
        )

        const results = await httpCleanup(config, { fetchImpl }).drop(['ABCD1234EFGH5678'])

        expect(results).toEqual([{ testId: 'ABCD1234EFGH5678', outcome: 'failed' }])
    })

    it('treats a missing outcome as failed', async () => {
        const { fetchImpl } = recorder(() => ok({ results: [{ testId: 'ABCD1234EFGH5678' }] }))

        const results = await httpCleanup(config, { fetchImpl }).drop(['ABCD1234EFGH5678'])

        expect(results).toEqual([{ testId: 'ABCD1234EFGH5678', outcome: 'failed' }])
    })

    it('treats a results field that is not an array as no answer at all', async () => {
        const { fetchImpl } = recorder(() => ok({ results: 'all good' }))

        const results = await httpCleanup(config, { fetchImpl }).drop(['ABCD1234EFGH5678'])

        expect(results).toEqual([{ testId: 'ABCD1234EFGH5678', outcome: 'failed' }])
    })

    it('ignores an entry whose testId is not a string', async () => {
        const { fetchImpl } = recorder(() =>
            ok({ results: [{ testId: 42, outcome: 'dropped' }] }),
        )

        const results = await httpCleanup(config, { fetchImpl }).drop(['ABCD1234EFGH5678'])

        expect(results).toEqual([{ testId: 'ABCD1234EFGH5678', outcome: 'failed' }])
    })

    it('ignores a non-numeric kept or cutoffMs', async () => {
        const { fetchImpl } = recorder(() => ok({ results: [], kept: 'lots', cutoffMs: null }))

        const sweep = await httpCleanup(config, { fetchImpl }).sweep([], 1000)

        expect(sweep.kept).toBe(0)
        expect(sweep.cutoffMs).toBe(1000)
    })
})

describe('httpCleanup.sweep', () => {
    it('posts the live ids and the requested age', async () => {
        const { calls, fetchImpl } = recorder(() => ok({ results: [], kept: 0, cutoffMs: 3600000 }))

        await httpCleanup(config, { fetchImpl }).sweep(['LIVE1111LIVE1111'], 86400000)

        expect(calls[0].url).toBe('https://example-testing.test/typo3/test-api/databases/sweep')
        expect(calls[0].body).toEqual({ keepTestIds: ['LIVE1111LIVE1111'], minimumAgeMs: 86400000 })
    })

    it('returns what the endpoint reclaimed and kept', async () => {
        const { fetchImpl } = recorder(() =>
            ok({
                results: [{ testId: 'OLDOLD1111OLDOLD', outcome: 'dropped' }],
                kept: 3,
                cutoffMs: 7200000,
            }),
        )

        const sweep = await httpCleanup(config, { fetchImpl }).sweep([], 1000)

        expect(sweep.results).toEqual([{ testId: 'OLDOLD1111OLDOLD', outcome: 'dropped' }])
        expect(sweep.kept).toBe(3)
        expect(sweep.cutoffMs).toBe(7200000)
    })

    // Sweeping is opportunistic: a site that cannot answer is not a run failure.
    it('reports nothing reclaimed when the endpoint is unreachable', async () => {
        const fetchImpl = (async () => {
            throw new Error('ECONNREFUSED')
        }) as unknown as typeof fetch

        const sweep = await httpCleanup(config, { fetchImpl }).sweep([], 1000)

        expect(sweep.results).toEqual([])
        expect(sweep.unreachable).toBe(true)
    })
})
