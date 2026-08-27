import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import { setToolkitConfig, type ToolkitConfig } from '#src/config.js'
import globalSetup, { preflightTestId, runHealthCheck, verifyApiVersion } from '#src/global-setup.js'
import { configForRun } from '../helpers.js'
import { REPLAY_TEST_ID, TEST_ID_HEADER, TEST_ID_PATTERN } from '#src/contract.js'
import { readAttempts } from '#src/state/attempt-registry.js'
import { ensureRunNamespace } from '#src/state/run-namespace.js'

let tmpRoot: string
let config: ToolkitConfig

beforeEach(() => {
    tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-preflight-'))
    config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
    ensureRunNamespace(config)
})

afterEach(() => {
    vi.unstubAllGlobals()
    fs.rmSync(tmpRoot, { recursive: true, force: true })
})

describe('verifyApiVersion', () => {
    function probeReporting(body: Record<string, unknown>, status = 200): typeof fetch {
        return (async (_url: string, init?: { headers?: Record<string, string> }) => {
            probes.push(init?.headers ?? {})
            return new Response(JSON.stringify(body), { status })
        }) as unknown as typeof fetch
    }

    let probes: Record<string, string>[]

    beforeEach(() => {
        probes = []
    })

    // Header-less on purpose: a request carrying a test ID would make the
    // extension provision a database, and against a too-old extension there
    // would then be no endpoint able to clean it up.
    it('probes without a test id', async () => {
        await verifyApiVersion(config, { fetchImpl: probeReporting({ ok: true, api: 1 }) })

        expect(probes[0][TEST_ID_HEADER]).toBeUndefined()
    })

    it('returns the version the endpoint reported', async () => {
        expect(await verifyApiVersion(config, { fetchImpl: probeReporting({ ok: true, api: 1 }) })).toBe(1)
    })

    // An unhealthy site still reports its version, and being too old is a
    // different problem from being unhealthy.
    it('reads the version from an unhealthy response too', async () => {
        const unhealthy = probeReporting({ ok: false, api: 1, checks: {} }, 503)

        await expect(verifyApiVersion(config, { fetchImpl: unhealthy })).resolves.toBe(1)
    })

    it('fails with an upgrade instruction when the extension is too old', async () => {
        const old = probeReporting({ ok: true, api: 0 })

        await expect(verifyApiVersion(config, { fetchImpl: old })).rejects.toThrow(/composer update/)
        await expect(verifyApiVersion(config, { fetchImpl: old })).rejects.toThrow(/plan2net\/playwright-toolkit/)
    })

    it('fails when the response carries no version at all', async () => {
        const noVersion = probeReporting({ ok: true, checks: {} })

        await expect(verifyApiVersion(config, { fetchImpl: noVersion })).rejects.toThrow(/too old|api/i)
    })

    it('accepts a newer extension than the minimum', async () => {
        await expect(
            verifyApiVersion(config, { fetchImpl: probeReporting({ ok: true, api: 99 }) }),
        ).resolves.toBe(99)
    })

    // Outside a Testing context the endpoint does not exist and the body is an
    // error page, so a bare parse failure would read as a JSON syntax error.
    it('explains a non-JSON answer as the extension not being loaded', async () => {
        const notFound = (async () =>
            new Response('<html>Not Found</html>', { status: 404 })) as unknown as typeof fetch

        await expect(verifyApiVersion(config, { fetchImpl: notFound })).rejects.toThrow(/Testing context/)
        await expect(verifyApiVersion(config, { fetchImpl: notFound })).rejects.toThrow(/status 404/)
    })

    // A PHP fatal answers 200 with an error page, so only the body names the cause.
    it('quotes the body it could not parse', async () => {
        const fatal = (async () =>
            new Response('<br /><b>Fatal error</b>: Uncaught InvalidArgumentException: no driver', {
                status: 200,
            })) as unknown as typeof fetch

        await expect(verifyApiVersion(config, { fetchImpl: fatal })).rejects.toThrow(
            /Uncaught InvalidArgumentException: no driver/,
        )
    })

    it('says so when the body is empty', async () => {
        const empty = (async () => new Response('', { status: 200 })) as unknown as typeof fetch

        await expect(verifyApiVersion(config, { fetchImpl: empty })).rejects.toThrow(/\(empty body\)/)
    })

    it('says which url it could not reach', async () => {
        const unreachable = (async () => {
            throw new Error('ECONNREFUSED')
        }) as unknown as typeof fetch

        await expect(verifyApiVersion(config, { fetchImpl: unreachable })).rejects.toThrow(
            /example-testing\.test/,
        )
    })
})

describe('globalSetup', () => {
    // The extension refuses a health request that carries no test ID, so this
    // wiring is the whole preflight. Unit-testing runHealthCheck and
    // preflightTestId separately cannot catch the call site losing the ID.
    it('sends a registered test id with the preflight request', async () => {
        const seen: Record<string, string>[] = []
        vi.stubGlobal(
            'fetch',
            async (_url: string, init: { headers?: Record<string, string> }) => {
                seen.push(init?.headers ?? {})
                return new Response(JSON.stringify({ ok: true, api: 1, checks: {} }), {
                    status: 200,
                })
            },
        )
        setToolkitConfig(config)

        await globalSetup()

        // The first request is the header-less version probe; the preflight follows.
        expect(seen[0][TEST_ID_HEADER]).toBeUndefined()

        const sentTestId = seen[1][TEST_ID_HEADER]
        expect(sentTestId).toMatch(TEST_ID_PATTERN)
        expect(readAttempts(config).map((attempt) => attempt.testId)).toContain(sentTestId)
    })

    // Order is the whole point: against a too-old extension, minting an ID first
    // would provision a database nothing could clean up.
    it('mints no test id when the extension is too old', async () => {
        const seen: Record<string, string>[] = []
        vi.stubGlobal('fetch', async (_url: string, init?: { headers?: Record<string, string> }) => {
            seen.push(init?.headers ?? {})
            return new Response(JSON.stringify({ ok: true, api: 0 }), { status: 200 })
        })
        setToolkitConfig(config)

        await expect(globalSetup()).rejects.toThrow(/too old/)

        expect(seen).toHaveLength(1)
        expect(readAttempts(config)).toEqual([])
    })
})

// A random preflight id would leave a database replay's teardown never drops.
describe('globalSetup in replay mode', () => {
    it('preflights the replay database and registers no attempt of its own', async () => {
        const seen: Record<string, string>[] = []
        vi.stubGlobal('fetch', async (_url: string, init?: { headers?: Record<string, string> }) => {
            seen.push(init?.headers ?? {})
            return new Response(JSON.stringify({ ok: true, api: 1, checks: {} }), { status: 200 })
        })
        const replay = { ...config, replay: true }
        setToolkitConfig(replay)

        await globalSetup()

        expect(seen[1][TEST_ID_HEADER]).toBe(REPLAY_TEST_ID)
        expect(readAttempts(replay)).toEqual([])
    })
})

describe('preflightTestId', () => {
    it('mints an id the contract accepts', () => {
        expect(preflightTestId(config)).toMatch(TEST_ID_PATTERN)
    })

    it('registers the id so teardown reclaims its database', () => {
        const testId = preflightTestId(config)

        expect(readAttempts(config).map((attempt) => attempt.testId)).toEqual([testId])
    })

    it('registers it under a recognisable key', () => {
        preflightTestId(config)

        expect(readAttempts(config)[0].key).toContain('preflight')
    })
})

describe('runHealthCheck', () => {
    it('sends the test id header', async () => {
        const seen: Array<Record<string, string>> = []
        const fetchImpl = (async (_url: string, init?: RequestInit) => {
            seen.push((init?.headers ?? {}) as Record<string, string>)

            return new Response(JSON.stringify({ ok: true }), { status: 200 })
        }) as unknown as typeof fetch

        await runHealthCheck('https://example-testing.test', { testId: 'ABCD1234EFGH5678', fetchImpl })

        expect(seen[0][TEST_ID_HEADER]).toBe('ABCD1234EFGH5678')
    })

    it('still reports a failing check', async () => {
        let callCount = 0
        const fetchImpl = (async () => {
            callCount++

            return new Response(
                JSON.stringify({ ok: false, checks: { database: { ok: false, detail: 'schema dump absent' } } }),
                { status: 503 },
            )
        }) as unknown as typeof fetch

        await expect(
            runHealthCheck('https://example-testing.test', { testId: 'ABCD1234EFGH5678', fetchImpl }),
        ).rejects.toThrow(/database: schema dump absent/)
        expect(callCount).toBe(1)
    })
})
