import { beforeEach, describe, expect, it } from 'vitest'
import { setToolkitConfig, type ToolkitConfig } from '#src/config.js'
import { recordSaver, saveRecord, type FormPoster } from '#src/http/record-edit.js'

const TEST_ID = 'ABCD1234EFGH5678'

function config(): ToolkitConfig {
    return {
        testingURL: 'https://example-testing.test',
        paths: {
            consumerRoot: '/srv/project',
            stateDir: '/srv/project/.test-state',
            sessionDir: '/srv/project/var/session',
        },
    }
}

interface Posted {
    url: string
    multipart: Record<string, string>
    headers: Record<string, string>
    maxRedirects?: number
}

function fakePoster(
    response: { status: number; location?: string; body?: string } = {
        status: 302,
        location: '/typo3/record/edit?edit%5Bpages%5D%5B42%5D=edit&token=abc',
    },
): { poster: FormPoster; posted: Posted[] } {
    const posted: Posted[] = []

    const poster: FormPoster = {
        async post(url, options) {
            posted.push({
                url,
                multipart: options.multipart,
                headers: options.headers,
                maxRedirects: options.maxRedirects,
            })

            return {
                status: () => response.status,
                headers: (): Record<string, string> =>
                    response.location ? { location: response.location } : {},
                text: async () => response.body ?? '',
            }
        },
    }

    return { poster, posted }
}

const context = {
    baseUrl: 'https://example-testing.test',
    backendPath: '/typo3',
    testId: TEST_ID,
    routeToken: 'the-route-token',
}

beforeEach(() => {
    setToolkitConfig(config())
})

describe('saveRecord', () => {
    it('posts to the backend edit route the browser posts to', async () => {
        const { poster, posted } = fakePoster()

        await saveRecord(poster, context, {
            table: 'pages',
            identifier: 'NEWpage',
            target: 1,
            data: { pages: { NEWpage: { title: 'A page' } } },
        })

        expect(posted).toHaveLength(1)
        expect(posted[0].url).toBe(
            'https://example-testing.test/typo3/record/edit' +
                '?edit%5Bpages%5D%5B1%5D=new&token=the-route-token',
        )
    })

    // BE/entryPoint moves every backend route, record/edit included.
    it('posts under the backend path the session endpoint named', async () => {
        const { poster, posted } = fakePoster()

        await saveRecord(
            poster,
            { ...context, backendPath: '/admin' },
            {
                table: 'pages',
                identifier: 'NEWpage',
                target: 1,
                data: { pages: { NEWpage: { title: 'A page' } } },
            },
        )

        expect(posted[0].url).toBe(
            'https://example-testing.test/admin/record/edit' +
                '?edit%5Bpages%5D%5B1%5D=new&token=the-route-token',
        )
    })

    // NEW is DataHandler's own marker for "create"; anything else is a uid the
    // form opens for editing.
    it('opens an existing record for editing rather than creating another', async () => {
        const { poster, posted } = fakePoster({
            status: 302,
            location: '/typo3/record/edit?edit%5Bpages%5D%5B7%5D=edit',
        })

        const uid = await saveRecord(poster, context, {
            table: 'pages',
            identifier: '7',
            target: 7,
            data: { pages: { 7: { title: 'Renamed' } } },
        })

        expect(posted[0].url).toContain('edit%5Bpages%5D%5B7%5D=edit')
        expect(uid).toBe(7)
    })

    it('sends the fields the way the edit form sends them', async () => {
        const { poster, posted } = fakePoster()

        await saveRecord(poster, context, {
            table: 'pages',
            identifier: 'NEWpage',
            target: 1,
            data: { pages: { NEWpage: { title: 'A page', hidden: 0 } } },
        })

        expect(posted[0].multipart).toEqual({
            'data[pages][NEWpage][title]': 'A page',
            'data[pages][NEWpage][hidden]': '0',
            doSave: '1',
            _savedok: '1',
        })
    })

    // The key below is what a flexform field posts, in every supported version.
    it('sends a nested value as one key per leaf', async () => {
        const { poster, posted } = fakePoster({
            status: 302,
            location: '/typo3/record/edit?edit%5Btt_content%5D%5B9%5D=edit',
        })

        await saveRecord(poster, context, {
            table: 'tt_content',
            identifier: 'NEWcontent',
            target: 1,
            data: {
                tt_content: {
                    NEWcontent: {
                        CType: 'list',
                        pi_flexform: {
                            data: { sDEF: { lDEF: { 'settings.limit': { vDEF: 10 } } } },
                        },
                    },
                },
            },
        })

        expect(posted[0].multipart).toEqual({
            'data[tt_content][NEWcontent][CType]': 'list',
            'data[tt_content][NEWcontent][pi_flexform][data][sDEF][lDEF][settings.limit][vDEF]':
                '10',
            doSave: '1',
            _savedok: '1',
        })
    })

    it('carries the test id so the write lands in this test database', async () => {
        const { poster, posted } = fakePoster()

        await saveRecord(poster, context, {
            table: 'pages',
            identifier: 'NEWpage',
            target: 1,
            data: { pages: { NEWpage: { title: 'A page' } } },
        })

        expect(posted[0].headers['X-Playwright-Test-Id']).toBe(TEST_ID)
    })

    it('reads the new uid out of the redirect the controller answers with', async () => {
        const { poster } = fakePoster()

        const uid = await saveRecord(poster, context, {
            table: 'pages',
            identifier: 'NEWpage',
            target: 1,
            data: { pages: { NEWpage: { title: 'A page' } } },
        })

        expect(uid).toBe(42)
    })

    it('follows no redirect, or the uid would be gone by the time we look', async () => {
        const { poster, posted } = fakePoster()

        await saveRecord(poster, context, {
            table: 'pages',
            identifier: 'NEWpage',
            target: 1,
            data: { pages: { NEWpage: { title: 'A page' } } },
        })

        expect(posted[0].maxRedirects).toBe(0)
    })

    // record/edit answers 200 with an HTML error page for a rejected save, which
    // is why a missing redirect has to fail rather than be read as success.
    it('throws when the save did not redirect', async () => {
        const { poster } = fakePoster({ status: 200, body: '<html>Login</html>' })

        await expect(
            saveRecord(poster, context, {
                table: 'pages',
                identifier: 'NEWpage',
                target: 1,
                data: { pages: { NEWpage: { title: 'A page' } } },
            }),
        ).rejects.toThrow(/did not save/i)
    })

    it('throws when the redirect names no uid for the record', async () => {
        const { poster } = fakePoster({ status: 302, location: '/typo3/login' })

        await expect(
            saveRecord(poster, context, {
                table: 'pages',
                identifier: 'NEWpage',
                target: 1,
                data: { pages: { NEWpage: { title: 'A page' } } },
            }),
        ).rejects.toThrow(/no uid/i)
    })

    it('refuses to post without a route token, rather than being bounced to the login form', async () => {
        const { poster } = fakePoster()

        await expect(
            saveRecord(
                poster,
                { ...context, routeToken: '' },
                {
                    table: 'pages',
                    identifier: 'NEWpage',
                    target: 1,
                    data: { pages: { NEWpage: { title: 'A page' } } },
                },
            ),
        ).rejects.toThrow(/route token/i)
    })
})

describe('recordSaver', () => {
    it('writes an arbitrary table through the edit route', async () => {
        const { poster, posted } = fakePoster({
            status: 302,
            location: '/typo3/record/edit?edit%5Btx_vendor_domain_model_thing%5D%5B42%5D=edit&token=abc',
        })

        const uid = await recordSaver(poster, context)({
            table: 'tx_vendor_domain_model_thing',
            identifier: '1',
            target: 1,
            data: { tx_vendor_domain_model_thing: { 1: { title: 'A thing' } } },
        })

        expect(uid).toBe(42)
        expect(posted[0].url).toContain('edit%5Btx_vendor_domain_model_thing%5D%5B1%5D=edit')
        expect(posted[0].multipart['data[tx_vendor_domain_model_thing][1][title]']).toBe('A thing')
    })

    it('refuses without a route token, rather than posting into a login redirect', async () => {
        const { poster } = fakePoster()

        await expect(
            recordSaver(poster, { ...context, routeToken: '' })({
                table: 'tx_vendor_domain_model_thing',
                identifier: '1',
                target: 1,
                data: {},
            }),
        ).rejects.toThrow(/route token/)
    })
})
