import { beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as path from 'path'
import { fileURLToPath } from 'url'
import { setToolkitConfig, type ToolkitConfig } from '#src/config.js'
import { registerContentTypes } from '#src/builders/content-factory.js'
import { ContentBuilder } from '#src/builders/content-builder.js'
import { PageBuilder } from '#src/builders/page-builder.js'
import { flexForm, imageCrop } from '#src/builders/fields.js'
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
function fakePage(uid: number | number[], refusalBody?: string, testId: string = TEST_ID) {
    const posted: Posted[] = []
    const remaining = Array.isArray(uid) ? [...uid] : []
    const nextUid = () => (Array.isArray(uid) ? (remaining.shift() ?? 0) : uid)

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
                const location = `/typo3/record/edit?edit[${table}][${nextUid()}]=edit`

                return {
                    status: () => 302,
                    headers: () => ({ location }),
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

class ContainerContent implements ContentBuilderInterface {
    readonly type = 'container_demo'

    getFields(): ContentFields {
        return { CType: this.type, header: 'Container' }
    }

    getAdditionalRecords(): RecordDataMap {
        return { tt_content: { NEWchild: { CType: 'header', header: 'Inside' } } }
    }
}

/** A consumer CType with an item collection its builder exposes no setter for. */
class AccordionContent implements ContentBuilderInterface {
    readonly type = 'demo_accordion'

    getFields(): ContentFields {
        return { CType: this.type, header: 'Accordion' }
    }
}

class SelfOverwritingContent implements ContentBuilderInterface {
    readonly type = 'collide_demo'

    getFields(): ContentFields {
        return { CType: this.type }
    }

    getAdditionalRecords(contentIdentifier: string): RecordDataMap {
        return { tt_content: { [contentIdentifier]: { header: 'Oops' } } }
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

    it.each([
        ['page', '1'],
        ['link', '3'],
        ['shortcut', '4'],
        ['backend-user-section', '6'],
        ['mountpoint', '7'],
        ['spacer', '199'],
        ['folder', '254'],
    ] as const)('writes the %s doktype as %s', async (type, value) => {
        const { posted, page } = fakePage(9)

        await pageBuilder(page).withTitle('A page').withDoktype(type).create()

        expect(only(posted[0].dataMap.pages).doktype).toBe(value)
    })

    it('takes the number of a doktype a project registered itself', async () => {
        const { posted, page } = fakePage(9)

        await pageBuilder(page).withTitle('A page').withDoktype(303).create()

        expect(only(posted[0].dataMap.pages).doktype).toBe('303')
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

    it('keeps a flexform value nested instead of writing JSON into the column', async () => {
        const { posted, page } = fakePage(5)

        await contentBuilder(page)
            .onPage('12')
            .ofType('text')
            .configure((content) => {
                content.withField('pi_flexform', {
                    data: { sDEF: { lDEF: { 'settings.limit': { vDEF: 10 } } } },
                })
            })
            .create()

        const identifier = identifierOf(posted[0].dataMap.tt_content)
        expect(posted[0].fields).toMatchObject({
            [`data[tt_content][${identifier}][pi_flexform][data][sDEF][lDEF][settings.limit][vDEF]`]:
                '10',
        })
    })

    // Every element used to be posted with the page id, so a scenario's elements
    // rendered in the reverse of the order it created them.
    it('appends each element after the previous one on the same page', async () => {
        const { posted, page } = fakePage([11, 22])

        await contentBuilder(page).onPage('12').ofType('text').create()
        await contentBuilder(page).onPage('12').ofType('text').create()

        expect(only(posted[0].dataMap.tt_content)).toMatchObject({ pid: '12' })
        expect(only(posted[1].dataMap.tt_content)).toMatchObject({ pid: '-11' })
    })

    it('posts a relation the builder declares', async () => {
        const { posted, page } = fakePage(42)

        await contentBuilder(page)
            .onPage('12')
            .ofType('text')
            .configure((content) => content.withFileReference('assets', 7))
            .create()

        const reference = identifierOf(posted[0].dataMap.sys_file_reference)

        expect(only(posted[0].dataMap.tt_content).assets).toBe(reference)
        expect(posted[0].dataMap.sys_file_reference[reference]).toMatchObject({
            uid_local: '7',
            tablenames: 'tt_content',
            pid: '12',
        })
    })

    // The extra records used to be spread over the root table, replacing the element.
    it('keeps its own element when a builder writes tt_content rows too', async () => {
        registerContentTypes({ container_demo: ContainerContent })
        const { posted, page } = fakePage(42)

        await contentBuilder(page).onPage('12').ofType('container_demo').create()

        const rows = posted[0].dataMap.tt_content

        expect(Object.values(rows).map((row) => row.header)).toEqual(['Container', 'Inside'])
    })

    // getAdditionalRecords is handed the element's identifier, which is how a
    // builder ends up keying a row by it.
    it('refuses a record that reuses an identifier another surface wrote', async () => {
        registerContentTypes({ collide_demo: SelfOverwritingContent })
        const { page } = fakePage(42)

        await expect(
            contentBuilder(page).onPage('12').ofType('collide_demo').create(),
        ).rejects.toThrow(/tt_content/)
    })

    // For a CType whose builder has no convenience for the column.
    it('posts a relation named on the builder rather than the content type', async () => {
        const { posted, page } = fakePage(42)

        await contentBuilder(page)
            .onPage('12')
            .ofType('textmedia')
            .withFileReference('assets', 7)
            .create()

        const reference = identifierOf(posted[0].dataMap.sys_file_reference)

        expect(only(posted[0].dataMap.tt_content).assets).toBe(reference)
        expect(posted[0].dataMap.sys_file_reference[reference]).toMatchObject({ uid_local: '7' })
    })

    // Nothing the reader can see decides which surface ran first, so the token
    // order — which image is first — would be arbitrary.
    it('refuses a relation on a column the content type already relates', async () => {
        const { page } = fakePage(42)

        await expect(
            contentBuilder(page)
                .onPage('12')
                .ofType('textmedia')
                .configure((content) => content.withFileReference('assets', 1))
                .withFileReference('assets', 2)
                .create(),
        ).rejects.toThrow(/assets/)
    })

    it('lets a value on the builder override the content type', async () => {
        const { posted, page } = fakePage(42)

        await contentBuilder(page)
            .onPage('12')
            .ofType('text')
            .configure((content) => content.withHeader('From the type'))
            .withField('header', 'From the spec')
            .create()

        expect(only(posted[0].dataMap.tt_content).header).toBe('From the spec')
    })

    it('posts several references named on the builder', async () => {
        const { posted, page } = fakePage(42)

        await contentBuilder(page)
            .onPage('12')
            .ofType('textmedia')
            .withFileReferences('assets', [1, 2], { crop: imageCrop({ ratio: '16:9' }) })
            .create()

        const tokens = String(only(posted[0].dataMap.tt_content).assets).split(',')

        expect(tokens).toHaveLength(2)
        expect(posted[0].dataMap.sys_file_reference[tokens[0]].crop).toContain('16:9')
    })

    it('posts a child record named on the builder', async () => {
        registerContentTypes({ demo_accordion: AccordionContent })
        const { posted, page } = fakePage(42)

        await contentBuilder(page)
            .onPage('12')
            .ofType('demo_accordion')
            .withChild('items', 'tx_demo_accordion_item', (item) =>
                item.withField('title', 'First'),
            )
            .create()

        expect(only(posted[0].dataMap.tt_content).items).toBe(
            identifierOf(posted[0].dataMap.tx_demo_accordion_item),
        )
        expect(only(posted[0].dataMap.tx_demo_accordion_item)).toMatchObject({
            title: 'First',
            pid: '12',
        })
    })

    it('posts one child per item named on the builder', async () => {
        registerContentTypes({ demo_accordion: AccordionContent })
        const { posted, page } = fakePage(42)

        await contentBuilder(page)
            .onPage('12')
            .ofType('demo_accordion')
            .withChildren(
                'items',
                'tx_demo_accordion_item',
                ['First', 'Second'],
                (item, title) => item.withField('title', title),
            )
            .create()

        const rows = posted[0].dataMap.tx_demo_accordion_item
        const tokens = String(only(posted[0].dataMap.tt_content).items).split(',')

        expect(tokens.map((token) => rows[token].title)).toEqual(['First', 'Second'])
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
    Object.keys(dataMap.sys_file_reference ?? {}).forEach((id, index) => {
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

describe('the body a nested element posts', () => {
    it('is the one the extension proves DataHandler links to the child', async () => {
        const fixturePath = path.resolve(
            path.dirname(fileURLToPath(import.meta.url)),
            '../../../../../contract/content-nested-datamap.json',
        )
        const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf-8')) as Record<string, string>
        delete fixture._comment

        const { posted, page } = fakePage(42)
        await contentBuilder(page)
            .onPage('2')
            .ofType('text')
            .configure((element) => element.withHeader('With items'))
            .withChild('tx_relationstest_items', 'tx_relationstest_item', (item) =>
                item.withField('title', 'First').withFileReference('image', 1),
            )
            .create()

        const names: Record<string, string> = {
            [identifierOf(posted[0].dataMap.tt_content)]: 'NEWcontent',
            [identifierOf(posted[0].dataMap.tx_relationstest_item)]: 'NEWitem',
            [identifierOf(posted[0].dataMap.sys_file_reference)]: 'NEWitemimage',
        }
        const renamed = (value: string): string =>
            Object.entries(names).reduce((text, [from, to]) => text.split(from).join(to), value)

        expect(
            Object.fromEntries(
                Object.entries(posted[0].fields).map(([key, value]) => [
                    renamed(key),
                    renamed(value),
                ]),
            ),
        ).toEqual(fixture)
    })
})

describe('the body a flexform element posts', () => {
    it('is the one the extension proves the data structure is read against', async () => {
        const fixturePath = path.resolve(
            path.dirname(fileURLToPath(import.meta.url)),
            '../../../../../contract/content-flexform-datamap.json',
        )
        const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf-8')) as Record<string, string>
        delete fixture._comment

        const { posted, page } = fakePage(42)
        await contentBuilder(page)
            .onPage('1')
            .ofType('text')
            .configure((element) =>
                element
                    .withHeader('With settings')
                    .withField(
                        'pi_flexform',
                        flexForm({
                            sDEF: { 'settings.limit': ' 10 ' },
                            sFilter: { 'settings.categories': ' 1,2 ' },
                        }),
                    ),
            )
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
