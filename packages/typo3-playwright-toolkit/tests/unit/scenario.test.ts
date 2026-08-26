import { beforeEach, describe, expect, it } from 'vitest'
import { setToolkitConfig, type ToolkitConfig } from '#src/config.js'
import { openAuthenticatedPage, testName } from '#src/scenario.js'

function config(): ToolkitConfig {
    return {
        testingURL: 'https://example-testing.test',
        contentTypes: {},
        paths: {
            consumerRoot: '/srv/project',
            stateDir: '/srv/project/.test-state',
            sessionDir: '/srv/project/var/session',
        },
    }
}

/** Stands in for a Browser; records whether the context it handed out was closed. */
function fakeBrowser(failAt: 'newPage' | 'session' | 'json' | 'cookies' | 'never') {
    const state = {
        closed: false,
        contextOptions: undefined as undefined | { extraHTTPHeaders?: Record<string, string> },
        postedHeaders: undefined as undefined | Record<string, string>,
    }

    const context = {
        route: async () => undefined,
        addCookies: async () => {
            if ('cookies' === failAt) {
                throw new Error('cookies refused')
            }
        },
        close: async () => {
            state.closed = true
        },
        newPage: async () => {
            if ('newPage' === failAt) {
                throw new Error('no page')
            }

            return {
                request: {
                    post: async (_url: string, options: { headers: Record<string, string> }) => {
                        state.postedHeaders = options.headers
                        if ('session' === failAt) {
                            throw new Error('connection refused')
                        }

                        return {
                            ok: () => true,
                            status: () => 200,
                            text: async () => '',
                            json: async () => {
                                if ('json' === failAt) {
                                    throw new Error('not json')
                                }

                                return { cookieName: 'be_typo_user', cookieValue: 'jwt', tokens: {} }
                            },
                        }
                    },
                },
            }
        },
    }

    return {
        browser: {
            newContext: async (options: { extraHTTPHeaders?: Record<string, string> }) => {
                state.contextOptions = options

                return context
            },
        },
        state,
    }
}

beforeEach(() => {
    setToolkitConfig(config())
})

describe('openAuthenticatedPage', () => {
    // The caller only gets a close handle when this resolves, so anything that
    // throws after newContext() would otherwise leave the context open until the
    // worker's browser exits. A site that is down does that once per scenario file.
    it.each(['newPage', 'session', 'json', 'cookies'] as const)(
        'closes the context when %s fails',
        async (failAt) => {
            const { browser, state } = fakeBrowser(failAt)

            await expect(openAuthenticatedPage(browser as never, config(), 'ABCD1234EFGH5678')).rejects.toThrow()

            expect(state.closed).toBe(true)
        },
    )

    it('leaves the context open when it succeeds', async () => {
        const { browser, state } = fakeBrowser('never')

        await openAuthenticatedPage(browser as never, config(), 'ABCD1234EFGH5678')

        expect(state.closed).toBe(false)
    })

    // A context-wide header rides on page.request too, which context.route cannot reach.
    it('sets no context-wide toolkit headers at all', async () => {
        const { browser, state } = fakeBrowser('never')

        await openAuthenticatedPage(browser as never, config(), 'ABCD1234EFGH5678')

        expect(state.contextOptions?.extraHTTPHeaders).toBeUndefined()
    })

    it('still sends the secret on the session request itself', async () => {
        const { browser, state } = fakeBrowser('never')

        await openAuthenticatedPage(browser as never, config(), 'ABCD1234EFGH5678')

        expect(state.postedHeaders).toHaveProperty('X-Playwright-Toolkit-Secret')
        expect(state.postedHeaders).toHaveProperty('X-Playwright-Test-Id', 'ABCD1234EFGH5678')
    })
})

describe('testName', () => {
    it('is the scenario key on the first attempt', () => {
        expect(testName('accordion-simple', 1)).toBe('accordion-simple')
    })

    it('says which retry it is on a later one', () => {
        expect(testName('accordion-simple', 2)).toBe('accordion-simple #2')
    })
})
