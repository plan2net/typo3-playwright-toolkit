import { describe, expect, it } from 'vitest'
import { SECRET_HEADER } from '#src/http/api-secret.js'
import { TEST_ID_HEADER } from '#src/contract.js'
import { applyToolkitHeaders } from '#src/http/off-site-headers.js'
import type { ToolkitConfig } from '#src/config.js'

const TEST_ID = 'ABCD1234EFGH5678'

function config(): ToolkitConfig {
    return {
        testingURL: 'https://site-testing.test',
        contentTypes: {},
        paths: {
            consumerRoot: '/srv/project',
            stateDir: '/srv/project/.test-state',
            sessionDir: '/srv/project/var/session',
        },
    }
}

/** Runs the registered handler against one request and reports how it forwarded. */
async function handled(
    url: string,
    headers: Record<string, string>,
): Promise<{ headers: Record<string, string>; via: string }> {
    let handle: ((route: unknown) => Promise<void>) | undefined
    const context = {
        route: async (_matches: unknown, handler: (route: unknown) => Promise<void>) => {
            handle = handler
        },
    }

    await applyToolkitHeaders(context as never, config(), TEST_ID)

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
