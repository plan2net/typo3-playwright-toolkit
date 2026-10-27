import { describe, expect, it } from 'vitest'
import { SECRET_HEADER } from '#src/http/api-secret.js'
import { TEST_ID_HEADER } from '#src/contract.js'
import { applyToolkitHeaders, fetchForTest } from '#src/http/off-site-headers.js'
import { setToolkitConfig, type ToolkitConfig } from '#src/config.js'

const TEST_ID = 'ABCD1234EFGH5678'

function config(): ToolkitConfig {
    return {
        testingURL: 'https://site-testing.test',
        contentTypes: {},
        paths: {
            consumerRoot: '/srv/project',
            stateDir: '/srv/project/.test-state',
        },
    }
}

/** Runs the registered handler against one request and reports how it forwarded. */
async function handled(
    url: string,
    headers: Record<string, string>,
    origins?: ReadonlySet<string>,
): Promise<{ headers: Record<string, string>; via: string }> {
    let handle: ((route: unknown) => Promise<void>) | undefined
    const context = {
        route: async (_matches: unknown, handler: (route: unknown) => Promise<void>) => {
            handle = handler
        },
        request: {},
        on: () => undefined,
    }

    await applyToolkitHeaders(context as never, config(), TEST_ID, origins)

    const outcome = { headers: {} as Record<string, string>, via: 'nothing' }
    await handle?.({
        request: () => ({ url: () => url, headers: () => headers }),
        continue: async (options: { headers: Record<string, string> }) => {
            outcome.headers = options.headers
            outcome.via = 'continue'
        },
        fallback: async (options: { headers: Record<string, string> }) => {
            outcome.headers = options.headers
            outcome.via = 'fallback'
        },
    })

    return outcome
}

async function forward(url: string, headers: Record<string, string>): Promise<Record<string, string>> {
    return (await handled(url, headers)).headers
}

describe('applyToolkitHeaders', () => {
    it('gives the site under test its test id', async () => {
        const forwarded = await forward('https://site-testing.test/some/page', { accept: '*/*' })

        expect(forwarded[TEST_ID_HEADER]).toBe(TEST_ID)
        expect(forwarded.accept).toBe('*/*')
    })

    /**
     * The project's ordinary hostname serves the same codebase with the extension
     * gated off, so it has no more business seeing a toolkit header than a CDN does.
     */
    it('takes both headers off the project hostname', async () => {
        const forwarded = await forward('https://site.test/some/page', {
            [TEST_ID_HEADER.toLowerCase()]: TEST_ID,
            [SECRET_HEADER.toLowerCase()]: 'the-secret',
        })

        expect(forwarded[TEST_ID_HEADER.toLowerCase()]).toBeUndefined()
        expect(forwarded[SECRET_HEADER.toLowerCase()]).toBeUndefined()
    })

    it('takes both headers off a third party, keeping the rest', async () => {
        const forwarded = await forward('https://cdn.example.test/app.js', {
            accept: '*/*',
            [TEST_ID_HEADER.toLowerCase()]: TEST_ID,
            [SECRET_HEADER.toLowerCase()]: 'the-secret',
        })

        expect(forwarded).toEqual({ accept: '*/*' })
    })

    it('does not add the test id to a third party either', async () => {
        const forwarded = await forward('https://cdn.example.test/app.js', {})

        expect(forwarded[TEST_ID_HEADER]).toBeUndefined()
    })

    it('hands the site under test on to the handlers registered before it', async () => {
        expect((await handled('https://site-testing.test/page', {})).via).toBe('fallback')
    })

    it('hands a third party on as well, stripped rather than swallowed', async () => {
        expect((await handled('https://cdn.example.test/app.js', {})).via).toBe('fallback')
    })
})

describe('fetchForTest', () => {
    async function fetched(url: string, options?: { url?: string; headers?: Record<string, string> }) {
        setToolkitConfig(config())
        let sent: Record<string, string> = {}
        const route = {
            request: () => ({ url: () => url, headers: () => ({ accept: 'text/html' }) }),
            fetch: async (fetchOptions: { headers: Record<string, string> }) => {
                sent = fetchOptions.headers

                return 'the response'
            },
        }

        const response = await fetchForTest(route as never, TEST_ID, options)

        return { response, sent }
    }

    it('sends the test id to the site under test and returns the response', async () => {
        const { response, sent } = await fetched('https://site-testing.test/page')

        expect(response).toBe('the response')
        expect(sent[TEST_ID_HEADER]).toBe(TEST_ID)
        expect(sent.accept).toBe('text/html')
    })

    it('keeps the headers the test adds', async () => {
        const { sent } = await fetched('https://site-testing.test/page', { headers: { 'x-own': 'yes' } })

        expect(sent['x-own']).toBe('yes')
    })

    it('sends no toolkit header to another server, even one the test passes', async () => {
        const { sent } = await fetched('https://cdn.example.test/app.js', {
            headers: { [TEST_ID_HEADER.toLowerCase()]: TEST_ID },
        })

        expect(sent).toEqual({ accept: 'text/html' })
    })

    it('sends no toolkit header to another server, in any spelling', async () => {
        const { sent } = await fetched('https://cdn.example.test/app.js', {
            headers: { [TEST_ID_HEADER]: TEST_ID, [SECRET_HEADER.toUpperCase()]: 'the-secret' },
        })

        expect(sent).toEqual({ accept: 'text/html' })
    })

    it('checks the address it really fetches, when the test gives another one', async () => {
        const { sent } = await fetched('https://site-testing.test/page', { url: 'https://cdn.example.test/app.js' })

        expect(sent[TEST_ID_HEADER]).toBeUndefined()
    })
})

describe('several testing origins', () => {
    it('adds the test ID for every origin it was given', async () => {
        const origins = new Set(['https://site-testing.test', 'https://shop-testing.test'])

        const outcome = await handled('https://shop-testing.test/cart', {}, origins)

        expect(outcome.headers[TEST_ID_HEADER]).toBe(TEST_ID)
    })
})
