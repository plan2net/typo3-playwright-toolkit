import * as fs from 'fs'
import * as path from 'path'
import { fileURLToPath } from 'url'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { setToolkitConfig, type ToolkitConfig } from '#src/config.js'
import { recordSaver, saveRecord, type FormPoster } from '#src/http/record-edit.js'
import { SAVED_RECORD_HEADER } from '#src/contract.js'

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
    response: {
        status: number
        location?: string
        body?: string
        storedSlug?: string
        written?: Record<string, number>
        refused?: { errors: Array<{ message: string; table: string }>; count: number }
    } = {
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
                headers: (): Record<string, string> => ({
                    ...(response.location ? { location: response.location } : {}),
                    ...(response.storedSlug || response.written
                        ? {
                              [SAVED_RECORD_HEADER.toLowerCase()]: JSON.stringify({
                                  ...(response.storedSlug ? { slug: response.storedSlug } : {}),
                                  ...(response.written ? { written: response.written } : {}),
                              }),
                          }
                        : {}),
                    ...(response.refused
                        ? { 'x-playwright-record-diagnostics': JSON.stringify(response.refused) }
                        : {}),
                }),
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
    it('reports the slug the site stored, not the one it was asked for', async () => {
        const { poster } = fakePoster({
            status: 302,
            location: '/typo3/record/edit?edit%5Bpages%5D%5B42%5D=edit&token=abc',
            storedSlug: '/a-page-1',
        })

        const saved = await saveRecord(poster, context, {
            table: 'pages',
            identifier: 'NEWpage',
            target: 1,
            data: { pages: { NEWpage: { title: 'A page', slug: '/a-page' } } },
        })

        expect(saved.slug).toBe('/a-page-1')
    })

    it('reports how many records TYPO3 wrote per table', async () => {
        const { poster } = fakePoster({
            status: 302,
            location: '/typo3/record/edit?edit%5Bpages%5D%5B42%5D=edit&token=abc',
            written: { pages: 1 },
        })

        const saved = await saveRecord(poster, context, {
            table: 'pages',
            identifier: 'NEWpage',
            target: 1,
            data: { pages: { NEWpage: { title: 'A page' } } },
        })

        expect(saved.written).toEqual({ pages: 1 })
    })

    it('warns naming the tables TYPO3 wrote into that nobody asked for', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
        const { poster } = fakePoster({
            status: 302,
            location: '/typo3/record/edit?edit%5Bpages%5D%5B42%5D=edit&token=abc',
            written: { pages: 17, sys_redirect: 10 },
        })

        await saveRecord(poster, context, {
            table: 'pages',
            identifier: 'NEWpage',
            target: 1,
            data: { pages: { NEWpage: { title: 'A page', slug: '/a-page' } } },
        })

        const said = warn.mock.calls.map((call) => String(call[0])).join('\n')
        warn.mockRestore()

        expect(said).toMatch(/pages 17 \(16 not requested\)/)
        expect(said).toMatch(/sys_redirect 10 \(10 not requested\)/)
    })

    // A diagnostic that fires on every clean save gets tuned out.
    it('says nothing when a batch wrote exactly the records it asked for', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
        const { poster } = fakePoster({
            status: 302,
            location: '/typo3/record/edit?edit%5Btt_content%5D%5B42%5D=edit&token=abc',
            written: { tt_content: 3 },
        })

        await saveRecord(poster, context, {
            table: 'tt_content',
            identifier: 'NEWfirst',
            target: 1,
            data: {
                tt_content: {
                    NEWfirst: { header: 'First' },
                    NEWsecond: { header: 'Second' },
                    NEWthird: { header: 'Third' },
                },
            },
        })

        const said = warn.mock.calls.map((call) => String(call[0]))
        warn.mockRestore()

        expect(said).toEqual([])
    })

    it('parses the saved-record envelope the extension proves it answers with', async () => {
        const fixturePath = path.resolve(
            path.dirname(fileURLToPath(import.meta.url)),
            '../../../../../contract/saved-record-header.json',
        )
        const envelope = JSON.parse(fs.readFileSync(fixturePath, 'utf-8')) as Record<string, unknown>
        delete envelope._comment

        const poster: FormPoster = {
            async post() {
                return {
                    status: () => 302,
                    headers: () => ({
                        location: '/typo3/record/edit?edit%5Bpages%5D%5B42%5D=edit&token=abc',
                        [SAVED_RECORD_HEADER.toLowerCase()]: JSON.stringify(envelope),
                    }),
                    text: async () => '',
                }
            },
        }

        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
        const saved = await saveRecord(poster, context, {
            table: 'pages',
            identifier: 'NEWpage',
            target: 1,
            data: { pages: { NEWpage: { title: 'A page' } } },
        })
        warn.mockRestore()

        expect(saved.slug).toBe('/a-page-1')
        expect(saved.written).toEqual({ pages: 2, sys_redirect: 1 })
    })

    it('fails at the call that saved when TYPO3 refused something', async () => {
        const { poster } = fakePoster({
            status: 302,
            location: '/typo3/record/edit?edit%5Bpages%5D%5B42%5D=edit&token=abc',
            refused: {
                errors: [
                    {
                        message: "Attempt to insert a record on page '/gallery' (127) where this table is not allowed",
                        table: 'sys_file_reference',
                    },
                ],
                count: 2,
            },
        })

        await expect(
            saveRecord(poster, context, {
                table: 'pages',
                identifier: 'NEW1',
                target: 1,
                data: { pages: { NEW1: { title: 'x' } } },
            }),
        ).rejects.toThrow(/sys_file_reference[\s\S]*not allowed[\s\S]*1 more/)
    })

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

        const saved = await saveRecord(poster, context, {
            table: 'pages',
            identifier: '7',
            target: 7,
            data: { pages: { 7: { title: 'Renamed' } } },
        })

        expect(posted[0].url).toContain('edit%5Bpages%5D%5B7%5D=edit')
        expect(saved.uid).toBe(7)
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

        const saved = await saveRecord(poster, context, {
            table: 'pages',
            identifier: 'NEWpage',
            target: 1,
            data: { pages: { NEWpage: { title: 'A page' } } },
        })

        expect(saved.uid).toBe(42)
    })

    // The controller rewrites its edit configuration to name every record the datamap created.
    it('reads every uid the redirect names, in the order the datamap listed them', async () => {
        const { poster } = fakePoster({
            status: 302,
            location:
                '/typo3/record/edit?edit%5Btt_content%5D%5B7%5D=edit' +
                '&edit%5Btt_content%5D%5B8%5D=edit&edit%5Btt_content%5D%5B9%5D=edit&token=abc',
        })

        const saved = await saveRecord(poster, context, {
            table: 'tt_content',
            identifier: 'NEWfirst',
            target: 1,
            data: { tt_content: { NEWfirst: { header: 'First' } } },
        })

        expect(saved.uids).toEqual([7, 8, 9])
        expect(saved.uid).toBe(7)
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

        const saved = await recordSaver(poster, context)({
            table: 'tx_vendor_domain_model_thing',
            identifier: '1',
            target: 1,
            data: { tx_vendor_domain_model_thing: { 1: { title: 'A thing' } } },
        })

        expect(saved.uid).toBe(42)
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
