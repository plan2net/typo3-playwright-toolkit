import { beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as path from 'path'
import { fileURLToPath } from 'url'
import { setToolkitConfig, type ToolkitConfig } from '#src/config.js'
import { registerContentTypes } from '#src/builders/content-factory.js'
import { ContentBuilder } from '#src/builders/content-builder.js'
import { PageBuilder } from '#src/builders/page-builder.js'
import type { ContentBuilderInterface, ContentFields } from '#src/types/content-builder.js'
import type { RecordDataMap } from '#src/http/record-edit.js'

const TEST_ID = 'ABCD1234EFGH5678'
const ROUTE_TOKEN = 'route-token'

/** One request writes one record per table, and its identifier is not ours to know. */
function only(rows: Record<string, Record<string, unknown>>): Record<string, unknown> {
    return Object.values(rows)[0]
}

function identifierOf(rows: Record<string, Record<string, unknown>>): string {
    return Object.keys(rows)[0]
}

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

interface Posted {
    url: string
    fields: Record<string, string>
    dataMap: RecordDataMap
}

/** `data[table][identifier][column]` back into the datamap the assertions read. */
function toDataMap(fields: Record<string, string>): RecordDataMap {
    const dataMap: RecordDataMap = {}

    for (const [name, value] of Object.entries(fields)) {
        const match = name.match(/^data\[([^\]]+)\]\[([^\]]+)\]\[([^\]]+)\]$/)
        if (null === match) {
            continue
        }

        const [, table, identifier, column] = match
        dataMap[table] ??= {}
        dataMap[table][identifier] ??= {}
        dataMap[table][identifier][column] = value
    }

    return dataMap
}

/**
 * Stands in for a Playwright Page: the builders only use `url()`, `context()`
 * and `request.post()`. Answers the way the backend does — a redirect naming the
 * saved uid, or a rendered page when it refused the save.
 */
function fakePage(uid: number, refusalBody?: string, testId: string = TEST_ID) {
    const posted: Posted[] = []

    const page = {
        url: () => 'https://example-testing.test/typo3/module/web/layout',
        context: () => ({ testId }),
        testId,
        request: {
            async post(url: string, options: { headers: Record<string, string>; multipart: Record<string, string> }) {
                posted.push({ url, fields: options.multipart, dataMap: toDataMap(options.multipart) })

                if (undefined !== refusalBody) {
                    return { status: () => 200, headers: () => ({}), text: async () => refusalBody }
                }

                const table = decodeURIComponent(url).match(/edit\[(\w+)\]/)?.[1] ?? 'pages'

                return {
                    status: () => 302,
                    headers: () => ({ location: `/typo3/record/edit?edit[${table}][${uid}]=edit` }),
                    text: async () => '',
                }
            },
        },
    }

    return { posted, page: page as unknown as ConstructorParameters<typeof PageBuilder>[0] }
}

function pageBuilder(page: ConstructorParameters<typeof PageBuilder>[0]): PageBuilder {
    return new PageBuilder(page, { routeToken: ROUTE_TOKEN })
}

function contentBuilder(page: ConstructorParameters<typeof ContentBuilder>[0]): ContentBuilder {
    return new ContentBuilder(page, { routeToken: ROUTE_TOKEN })
}

class MediaContent implements ContentBuilderInterface {
    readonly type = 'media_demo'

    getFields(): ContentFields {
        return { CType: this.type, header: 'With media' }
    }

    getAdditionalRecords(contentIdentifier: string): RecordDataMap {
        return {
            sys_file_reference: {
                NEWref: { uid_local: 12, uid_foreign: contentIdentifier, tablenames: 'tt_content' },
            },
        }
    }
}

beforeEach(() => {
    setToolkitConfig(config())
    registerContentTypes({})
})

describe('PageBuilder', () => {
    it('returns the uid TYPO3 assigned, not a counter', async () => {
        const { page } = fakePage(4711)

        const result = await pageBuilder(page).withTitle('A page').create()

        expect(result.id).toBe('4711')
    })

    // The old builder returned counter+1, so two builders in one process handed
    // back the same fabricated uid regardless of what TYPO3 did.
    it('gives each created page the uid of its own response', async () => {
        const first = fakePage(10)
        const second = fakePage(25)

        const one = await pageBuilder(first.page).withTitle('One').create()
        const two = await pageBuilder(second.page).withTitle('Two').create()

        expect([one.id, two.id]).toEqual(['10', '25'])
    })

    it('posts the fields as FormEngine names them', async () => {
        const { posted, page } = fakePage(1)

        await pageBuilder(page).withTitle('A page').atParentId(7).withField('hidden', true).create()

        expect(only(posted[0].dataMap.pages)).toMatchObject({ title: 'A page', pid: '7', hidden: '1' })
    })

    it('posts to the backend edit route with the request token', async () => {
        const { posted, page } = fakePage(1)

        await pageBuilder(page).withTitle('A page').atParentId(7).create()

        expect(posted[0].url).toBe(
            'https://example-testing.test/typo3/record/edit' +
                `?edit%5Bpages%5D%5B7%5D=new&token=${ROUTE_TOKEN}`,
        )
    })

    it('throws instead of returning a made-up id when the save is refused', async () => {
        const { page } = fakePage(1, 'pid 7 does not exist')

        await expect(pageBuilder(page).withTitle('A page').create()).rejects.toThrow(/pid 7 does not exist/)
    })

    // update() used to post the *creation* URL, so it created a second record
    // instead of changing the one it was given.
    it('updates the record it was given rather than creating another', async () => {
        const { posted, page } = fakePage(42)

        await pageBuilder(page).withTitle('Renamed').update('42')

        expect(posted[0].url).toContain('edit%5Bpages%5D%5B42%5D=edit')
        expect(Object.keys(posted[0].dataMap.pages)).toEqual(['42'])
        expect(posted[0].dataMap.pages['42']).toMatchObject({ title: 'Renamed' })
    })

    it('attaches media by listing the reference on the page itself', async () => {
        const { posted, page } = fakePage(3)

        await pageBuilder(page).withTitle('A page').withExistingImage(99).create()

        expect(only(posted[0].dataMap.pages).media).toBe(identifierOf(posted[0].dataMap.sys_file_reference))
        expect(only(posted[0].dataMap.sys_file_reference)).toMatchObject({
            uid_local: '99',
            tablenames: 'pages',
            fieldname: 'media',
        })
        expect(only(posted[0].dataMap.sys_file_reference).uid_foreign).toBeUndefined()
    })

    // The crop column holds JSON text; a nested object would reach TYPO3 as one.
    it('serialises a crop configuration to a JSON string', async () => {
        const { posted, page } = fakePage(1)

        await pageBuilder(page)
            .withTitle('A page')
            .withExistingImage(5)
            .withImageCropFocus({
                default: {
                    cropArea: { x: 0, y: 0, width: 1, height: 1 },
                    selectedRatio: 'NaN',
                    focusArea: null,
                },
            })
            .create()

        const crop = only(posted[0].dataMap.sys_file_reference).crop
        expect(JSON.parse(crop as string).default.focusArea).toBeNull()
    })

    it('suffixes the slug with the test id so parallel databases cannot collide', async () => {
        const { posted, page } = fakePage(1)

        await pageBuilder(page).withTitle('A page').withSlug('/my-page').create()

        expect(only(posted[0].dataMap.pages).slug).toBe(`/my-page-${TEST_ID.toLowerCase()}`)
    })

    it('keeps the slug unsuffixed in replay mode', async () => {
        setToolkitConfig({ ...config(), replay: true })
        const { posted, page } = fakePage(1)

        await pageBuilder(page).withTitle('A page').withSlug('/my-page').create()

        expect(only(posted[0].dataMap.pages).slug).toBe('/my-page')
    })
})

describe('replay folder redirect', () => {
    function anchored() {
        return { routeToken: ROUTE_TOKEN, replayFolder: { id: '900', ownPages: new Set<string>() } }
    }

    beforeEach(() => {
        setToolkitConfig({ ...config(), replay: true })
    })

    it('moves a page anchored at a fixture page into the folder', async () => {
        const { posted, page } = fakePage(1)

        await new PageBuilder(page, anchored()).withTitle('A page').atParentId(1).create()

        expect(only(posted[0].dataMap.pages).pid).toBe('900')
    })

    it('keeps a page anchored at one the scenario created', async () => {
        const { posted, page } = fakePage(1)
        const context = anchored()
        context.replayFolder.ownPages.add('42')

        await new PageBuilder(page, context).withTitle('A child').atParentId(42).create()

        expect(only(posted[0].dataMap.pages).pid).toBe('42')
    })

    it('records the pages it creates so their children stay put', async () => {
        const { page } = fakePage(77)
        const context = anchored()

        await new PageBuilder(page, context).withTitle('A page').create()

        expect(context.replayFolder.ownPages.has('77')).toBe(true)
    })

    // uids are per table, so a tt_content 42 must not make a fixture page 42 look owned.
    it('does not record content elements as pages', async () => {
        const { page } = fakePage(42)
        const context = anchored()

        await new ContentBuilder(page, context).onPage('900').ofType('header').create()

        expect(context.replayFolder.ownPages.has('42')).toBe(false)
    })

    it('does not record an updated page', async () => {
        const { page } = fakePage(5)
        const context = anchored()

        await new PageBuilder(page, context).withTitle('Renamed').update('5')

        expect(context.replayFolder.ownPages.has('5')).toBe(false)
    })

    it('moves a content element anchored at a fixture page into the folder', async () => {
        const { posted, page } = fakePage(3)

        await new ContentBuilder(page, anchored()).onPage('1').ofType('header').create()

        expect(only(posted[0].dataMap.tt_content).pid).toBe('900')
    })

    it('leaves everything where it is outside replay', async () => {
        setToolkitConfig(config())
        const { posted, page } = fakePage(1)

        await pageBuilder(page).withTitle('A page').atParentId(1).create()

        expect(only(posted[0].dataMap.pages).pid).toBe('1')
    })
})

describe('ContentBuilder', () => {
    it('returns the uid of the created content element', async () => {
        registerContentTypes({ media_demo: MediaContent })
        const { page } = fakePage(908)

        const result = await contentBuilder(page).onPage('12').ofType('media_demo').create()

        expect(result.id).toBe('908')
    })

    it('refuses to build a content element with no page', () => {
        const { page } = fakePage(1)

        expect(() => contentBuilder(page).ofType('media_demo')).toThrow(/onPage/)
    })

    it('writes the additional records a content type declares', async () => {
        registerContentTypes({ media_demo: MediaContent })
        const { posted, page } = fakePage(5)

        await contentBuilder(page).onPage('12').ofType('media_demo').create()

        expect(posted[0].dataMap.sys_file_reference.NEWref).toMatchObject({
            uid_foreign: identifierOf(posted[0].dataMap.tt_content),
        })
    })

    it('throws when the backend refuses the content element', async () => {
        registerContentTypes({ media_demo: MediaContent })
        const { page } = fakePage(1, 'CType not allowed')

        await expect(contentBuilder(page).onPage('12').ofType('media_demo').create()).rejects.toThrow(
            /CType not allowed/,
        )
    })
})

// The typed setters below only compile because ofType() resolves a core CType to
// its own builder — an untyped `configure` would not see withBodyText at all.
describe('core CTypes through the whole builder', () => {
    it('posts a textmedia element with its FAL reference in one datamap', async () => {
        const { posted, page } = fakePage(42)

        const result = await contentBuilder(page)
            .onPage('12')
            .ofType('textmedia')
            .configure((builder) =>
                builder.withHeader('Hello').withBodyText('<p>Body</p>').withFile(7).withColumns(2),
            )
            .create()

        expect(result.id).toBe('42')
        expect(only(posted[0].dataMap.tt_content)).toMatchObject({
            CType: 'textmedia',
            header: 'Hello',
            bodytext: '<p>Body</p>',
            imagecols: '2',
            assets: identifierOf(posted[0].dataMap.sys_file_reference),
            pid: '12',
        })
        expect(only(posted[0].dataMap.sys_file_reference)).toMatchObject({
            uid_local: '7',
            fieldname: 'assets',
        })
    })

    it('posts a bullets element without the consumer registering anything', async () => {
        const { posted, page } = fakePage(8)

        await contentBuilder(page)
            .onPage('12')
            .ofType('bullets')
            .configure((builder) => builder.withItems(['one', 'two']))
            .create()

        expect(only(posted[0].dataMap.tt_content)).toMatchObject({
            CType: 'bullets',
            bodytext: 'one\ntwo',
        })
    })
})

/**
 * The identifiers are minted per request, so the fixture names them for a reader
 * and the posted body is renamed to match. Everything else compares literally.
 */
function withFixtureIdentifiers(
    post: { fields: Record<string, string> },
    dataMap: Record<string, Record<string, Record<string, unknown>>>,
): Record<string, string> {
    const names: Record<string, string> = { [identifierOf(dataMap.tt_content)]: 'NEWcontent' }
    Object.keys(dataMap.sys_file_reference).forEach((id, index) => {
        names[id] = `NEWimage${index}`
    })

    const renamed = (value: string): string =>
        Object.entries(names).reduce((text, [from, to]) => text.split(from).join(to), value)

    return Object.fromEntries(
        Object.entries(post.fields).map(([key, value]) => [renamed(key), renamed(value)]),
    )
}

describe('the body an image element posts', () => {
    it('is the one the extension proves DataHandler links', async () => {
        const fixturePath = path.resolve(
            path.dirname(fileURLToPath(import.meta.url)),
            '../../../../../contract/content-image-datamap.json',
        )
        const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf-8')) as Record<string, string>
        delete fixture._comment

        const { posted, page } = fakePage(42)
        await contentBuilder(page)
            .onPage('1')
            .ofType('image')
            .configure((element) => element.withHeader('Gallery').withFiles([1, 2]))
            .create()

        expect(withFixtureIdentifiers(posted[0], posted[0].dataMap)).toEqual(fixture)
    })
})

describe('duplicate slugs within one scenario', () => {
    it('refuses a slug another page already claimed', async () => {
        const context = { routeToken: ROUTE_TOKEN, usedSlugs: new Set<string>() }
        const first = fakePage(10)
        const second = fakePage(11)

        await new PageBuilder(first.page, context).withTitle('One').withSlug('/same').create()

        await expect(
            new PageBuilder(second.page, context).withTitle('Two').withSlug('/same').create(),
        ).rejects.toThrow(/slug/)
    })

    it('allows the same slug in another scenario', async () => {
        const first = fakePage(10)
        const second = fakePage(11)

        await new PageBuilder(first.page, { routeToken: ROUTE_TOKEN, usedSlugs: new Set() })
            .withTitle('One')
            .withSlug('/same')
            .create()

        await expect(
            new PageBuilder(second.page, { routeToken: ROUTE_TOKEN, usedSlugs: new Set() })
                .withTitle('Two')
                .withSlug('/same')
                .create(),
        ).resolves.toMatchObject({ id: '11' })
    })

    // update() re-saves an existing page with its own slug.
    it('does not trip when a page is updated', async () => {
        const context = { routeToken: ROUTE_TOKEN, usedSlugs: new Set<string>() }
        const { page } = fakePage(10)

        await new PageBuilder(page, context).withTitle('One').withSlug('/same').create()

        await expect(
            new PageBuilder(page, context).withTitle('Renamed').withSlug('/same').update('10'),
        ).resolves.toMatchObject({ id: '10' })
    })
})
