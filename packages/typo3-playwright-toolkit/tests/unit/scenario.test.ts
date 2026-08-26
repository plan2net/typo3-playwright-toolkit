import { beforeEach, describe, expect, it } from 'vitest'
import { setToolkitConfig, type ToolkitConfig } from '#src/config.js'
import { buildScenarioContext, createScenarioFolder, openAuthenticatedPage, scenarioName } from '#src/scenario.js'
import type { RecordDataMap } from '#src/http/record-edit.js'

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
        postedData: undefined as undefined | Record<string, unknown>,
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
                    post: async (
                        _url: string,
                        options: { headers: Record<string, string>; data: Record<string, unknown> },
                    ) => {
                        state.postedHeaders = options.headers
                        state.postedData = options.data
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

describe('openAuthenticatedPage in replay mode', () => {
    const replayConfig = (): ToolkitConfig => ({ ...config(), replay: true })

    it('asks for a replay session and sends no test-ID header', async () => {
        const { browser, state } = fakeBrowser('never')

        await openAuthenticatedPage(browser as never, replayConfig(), '')

        expect(state.postedData).toMatchObject({ replay: true })
        expect(state.postedHeaders).not.toHaveProperty('X-Playwright-Test-Id')
        expect(state.postedHeaders).toHaveProperty('X-Playwright-Toolkit-Secret')
    })

    it('says nothing about replay on a normal run', async () => {
        const { browser, state } = fakeBrowser('never')

        await openAuthenticatedPage(browser as never, config(), 'ABCD1234EFGH5678')

        expect(state.postedData).not.toHaveProperty('replay')
    })
})

describe('createScenarioFolder', () => {
    function folderPage(uid: number) {
        const posted: { fields: Record<string, string> }[] = []

        const page = {
            url: () => 'https://example-testing.test/typo3',
            context: () => ({}),
            request: {
                post: async (_url: string, options: { multipart: Record<string, string> }) => {
                    posted.push({ fields: options.multipart })

                    return {
                        status: () => 302,
                        headers: () => ({ location: `/typo3/record/edit?edit[pages][${uid}]=edit` }),
                        text: async () => '',
                    }
                },
            },
        }

        return { posted, page }
    }

    function dataMap(fields: Record<string, string>): RecordDataMap {
        const map: RecordDataMap = {}
        for (const [name, value] of Object.entries(fields)) {
            const match = name.match(/^data\[([^\]]+)\]\[([^\]]+)\]\[([^\]]+)\]$/)
            if (null !== match) {
                const [, table, identifier, column] = match
                map[table] ??= {}
                map[table][identifier] ??= {}
                map[table][identifier][column] = value
            }
        }

        return map
    }

    beforeEach(() => {
        setToolkitConfig({ ...config(), replay: true })
    })

    it('creates a sysfolder named after the scenario under the fixture root', async () => {
        const { posted, page } = folderPage(900)

        await createScenarioFolder(page as never, { routeToken: 'tok' }, 'news')

        const record = Object.values(dataMap(posted[0].fields).pages)[0]
        expect(record).toMatchObject({ doktype: '254', title: 'news', pid: '1' })
    })

    it('answers the folder uid with nothing claimed yet', async () => {
        const { page } = folderPage(900)

        const folder = await createScenarioFolder(page as never, { routeToken: 'tok' }, 'news')

        expect(folder.id).toBe('900')
        expect(folder.ownPages.size).toBe(0)
    })

    describe('the context a scenario setup receives', () => {
        const session = { backendPath: '/typo3', routeToken: 'tok' }
        const replayConfig = (): ToolkitConfig => ({ ...config(), replay: true })

        it('carries the folder in replay mode', async () => {
            const { page } = folderPage(900)

            const context = await buildScenarioContext(page as never, replayConfig(), session, '', 'news')

            expect(context.replayFolder?.id).toBe('900')
        })

        // Otherwise the folder would be created inside itself.
        it('creates the folder without an anchor of its own', async () => {
            const { posted, page } = folderPage(900)

            await buildScenarioContext(page as never, replayConfig(), session, '', 'news')

            expect(Object.values(dataMap(posted[0].fields).pages)[0]).toMatchObject({ pid: '1' })
        })

        it('creates no folder outside replay mode', async () => {
            setToolkitConfig(config())
            const { posted, page } = folderPage(900)

            const context = await buildScenarioContext(
                page as never,
                { ...config(), replay: false },
                session,
                'ABCD1234EFGH5678',
                'news',
            )

            expect(context.replayFolder).toBeUndefined()
            expect(posted).toHaveLength(0)
        })
    })
})

// This lands in the backend's title bar next to the site name, so a person has to
// recognise which of eight open tabs they are looking at. The scenario key cannot
// do that job: it is a sanitised path with a hash on the end.
describe('scenarioName', () => {
    it('is the file name without its directory or suffix', () => {
        expect(scenarioName('/srv/project/tests/news-archive-filter.spec.ts')).toBe(
            'news-archive-filter',
        )
    })

    it('drops the test suffix too', () => {
        expect(scenarioName('tests/news.test.ts')).toBe('news')
    })

    it('leaves everything else alone', () => {
        expect(scenarioName('tests/my_page.editorial.spec.ts')).toBe('my_page.editorial')
    })

    it('keeps the file name when there is nothing else left', () => {
        expect(scenarioName('tests/.spec.ts')).toBe('.spec.ts')
    })
})
