import { beforeEach, describe, expect, it } from 'vitest'
import { setToolkitConfig, type ToolkitConfig } from '#src/config.js'
import { registerContentTypes } from '#src/builders/content-factory.js'
import { ContentBuilder, saveBatch } from '#src/builders/content-builder.js'
import { PageBuilder } from '#src/builders/page-builder.js'
import { SAVED_RECORD_HEADER, TRANSLATED_RECORD_HEADER } from '#src/contract.js'

const TEST_ID = 'ABCD1234EFGH5678'
const CONTEXT = { routeToken: 'edit-token', commandToken: 'command-token' }

function config(): ToolkitConfig {
    return {
        testingURL: 'https://example-testing.test',
        contentTypes: {},
        paths: { consumerRoot: '/srv/project', stateDir: '/srv/project/.test-state' },
    }
}

interface Posted {
    url: string
    fields: Record<string, string>
}

interface Translation {
    uid: number
    pid: number
    references?: Record<string, number[]>
    truncated?: boolean
    slug?: string
}

/** Answers record/commit as the extension does, and record/edit with the redirect naming the record. */
function backend(translation: Translation, savedSlug?: string) {
    const posted: Posted[] = []

    const page = {
        url: () => 'https://example-testing.test/typo3/module/web/layout',
        context: () => ({ testId: TEST_ID }),
        testId: TEST_ID,
        request: {
            async post(url: string, options: { multipart: Record<string, string> }) {
                posted.push({ url, fields: options.multipart })

                if (url.includes('/record/commit')) {
                    const { slug, ...envelope } = translation

                    return {
                        status: () => 200,
                        headers: () => ({
                            [TRANSLATED_RECORD_HEADER.toLowerCase()]: JSON.stringify({ references: {}, ...envelope }),
                            ...(slug ? { [SAVED_RECORD_HEADER.toLowerCase()]: JSON.stringify({ slug }) } : {}),
                        }),
                        text: async () => '',
                    }
                }

                const table = decodeURIComponent(url).match(/edit\[(\w+)\]\[(\d+)\]/)
                return {
                    status: () => 302,
                    headers: () => ({
                        location: `/typo3/record/edit?edit[${table?.[1]}][${table?.[2]}]=edit`,
                        ...(savedSlug ? { [SAVED_RECORD_HEADER.toLowerCase()]: JSON.stringify({ slug: savedSlug }) } : {}),
                    }),
                    text: async () => '',
                }
            },
        },
    }

    return { posted, page: page as unknown as ConstructorParameters<typeof PageBuilder>[0] }
}

function newIdentifiers(fields: Record<string, string>, table: string): string[] {
    return [
        ...new Set(
            Object.keys(fields)
                .map((name) => name.match(new RegExp(`^data\\[${table}\\]\\[(NEW[^\\]]+)\\]`))?.[1])
                .filter((identifier): identifier is string => undefined !== identifier),
        ),
    ]
}

beforeEach(() => {
    setToolkitConfig(config())
    registerContentTypes({})
})

describe('translating a page', () => {
    it('sends the backend its localize command for the origin', async () => {
        const { posted, page } = backend({ uid: 3, pid: 1, slug: '/translate-to-german-products' })

        await new PageBuilder(page, CONTEXT).translate('2', 1).create()

        expect(posted[0].url).toBe('https://example-testing.test/typo3/record/commit?token=command-token')
        expect(posted[0].fields).toEqual({ 'cmd[pages][2][localize]': '1' })
    })

    it('answers the translation and the slug TYPO3 gave it', async () => {
        const { page } = backend({ uid: 3, pid: 1, slug: '/translate-to-german-products' })

        const translated = await new PageBuilder(page, CONTEXT).translate('2', 1).create()

        expect(translated).toEqual({ id: '3', slug: '/translate-to-german-products' })
    })

    it('shows the translation, which TYPO3 creates hidden', async () => {
        const { posted, page } = backend({ uid: 3, pid: 1 })

        await new PageBuilder(page, CONTEXT).translate('2', 1).create()

        expect(posted[1].fields).toMatchObject({ 'data[pages][3][hidden]': '0' })
    })

    it('keeps a translation hidden that the test hides', async () => {
        const { posted, page } = backend({ uid: 3, pid: 1 })

        await new PageBuilder(page, CONTEXT).translate('2', 1).withField('hidden', true).create()

        expect(posted[1].fields).toMatchObject({ 'data[pages][3][hidden]': '1' })
    })

    it('saves what was set on the translation, as its own value rather than the origin one', async () => {
        const { posted, page } = backend({ uid: 3, pid: 1 })

        await new PageBuilder(page, CONTEXT).translate('2', 1).withTitle('Produkte').create()

        expect(posted[1].url).toContain('edit%5Bpages%5D%5B3%5D=edit')
        expect(posted[1].fields).toMatchObject({
            'data[pages][3][title]': 'Produkte',
            'data[pages][3][l10n_state][title]': 'custom',
        })
    })

    it('answers the slug the save stored', async () => {
        const { page } = backend({ uid: 3, pid: 1, slug: '/translate-to-german-products' }, `/produkte-${TEST_ID.toLowerCase()}`)

        const translated = await new PageBuilder(page, CONTEXT).translate('2', 1).withSlug('/produkte').create()

        expect(translated.slug).toBe(`/produkte-${TEST_ID.toLowerCase()}`)
    })

    it('adds media after the copies of the origin, in the language of the translation', async () => {
        const { posted, page } = backend({ uid: 3, pid: 1, references: { media: [6, 7] } })

        await new PageBuilder(page, CONTEXT).translate('2', 1).withFileReference('media', 900001).create()

        const [added] = newIdentifiers(posted[1].fields, 'sys_file_reference')
        expect(posted[1].fields).toMatchObject({
            'data[pages][3][media]': `6,7,${added}`,
            'data[pages][3][l10n_state][media]': 'custom',
            [`data[sys_file_reference][${added}][uid_local]`]: '900001',
            [`data[sys_file_reference][${added}][pid]`]: '3',
            [`data[sys_file_reference][${added}][sys_language_uid]`]: '1',
        })
    })

    it('changes a copied reference', async () => {
        const { posted, page } = backend({ uid: 3, pid: 1, references: { media: [6, 7] } })

        await new PageBuilder(page, CONTEXT)
            .translate('2', 1)
            .withCopiedFileReference('media', 1, { alternative: 'Zweites Bild' })
            .create()

        expect(posted[1].fields).toMatchObject({
            'data[pages][3][media]': '6,7',
            'data[sys_file_reference][7][alternative]': 'Zweites Bild',
        })
    })

    it('refuses a parent of its own, which a translation takes from its origin', async () => {
        const { posted, page } = backend({ uid: 3, pid: 1 })

        await expect(new PageBuilder(page, CONTEXT).translate('2', 1).atParentId(5).create()).rejects.toThrow(
            /takes its parent and its type from the page it translates/,
        )
        expect(posted).toEqual([])
    })
})

describe('translating a content element', () => {
    it('sends localize for a translation connected to its origin', async () => {
        const { posted, page } = backend({ uid: 8, pid: 2 })

        const translated = await new ContentBuilder(page, CONTEXT).translate('5', 1).create()

        expect(posted[0].fields).toEqual({ 'cmd[tt_content][5][localize]': '1' })
        expect(translated).toEqual({ id: '8' })
    })

    it('shows the translation, which TYPO3 creates hidden', async () => {
        const { posted, page } = backend({ uid: 8, pid: 2 })

        await new ContentBuilder(page, CONTEXT).translate('5', 1).create()

        expect(posted[1].fields).toMatchObject({ 'data[tt_content][8][hidden]': '0' })
    })

    it('sends copyToLanguage for a free copy, which keeps no state per field', async () => {
        const { posted, page } = backend({ uid: 8, pid: 2 })

        await new ContentBuilder(page, CONTEXT).copyTo('5', 1).withField('header', 'Hallo').create()

        expect(posted[0].fields).toEqual({ 'cmd[tt_content][5][copyToLanguage]': '1' })
        expect(posted[1].fields).toEqual({
            'data[tt_content][8][hidden]': '0',
            'data[tt_content][8][header]': 'Hallo',
            doSave: '1',
            _savedok: '1',
        })
    })

    it('saves the setters of its content type, but not the type itself', async () => {
        const { posted, page } = backend({ uid: 8, pid: 2 })

        await new ContentBuilder(page, CONTEXT)
            .translate('5', 1, 'textmedia')
            .configure((element) => element.withHeader('Hallo'))
            .create()

        expect(posted[1].fields).toMatchObject({
            'data[tt_content][8][header]': 'Hallo',
            'data[tt_content][8][l10n_state][header]': 'custom',
        })
        expect(posted[1].fields).not.toHaveProperty('data[tt_content][8][CType]')
        expect(posted[1].fields).not.toHaveProperty('data[tt_content][8][colPos]')
    })

    it('adds a file after the copies, on the page the translation is on', async () => {
        const { posted, page } = backend({ uid: 8, pid: 2, references: { assets: [21, 22] } })

        await new ContentBuilder(page, CONTEXT)
            .translate('5', 1, 'textmedia')
            .configure((element) => element.withFile(900001))
            .create()

        const [added] = newIdentifiers(posted[1].fields, 'sys_file_reference')
        expect(posted[1].fields).toMatchObject({
            'data[tt_content][8][assets]': `21,22,${added}`,
            [`data[sys_file_reference][${added}][pid]`]: '2',
            [`data[sys_file_reference][${added}][sys_language_uid]`]: '1',
        })
    })

    it('changes a copied reference', async () => {
        const { posted, page } = backend({ uid: 8, pid: 2, references: { assets: [21, 22] } })

        await new ContentBuilder(page, CONTEXT)
            .translate('5', 1)
            .withCopiedFileReference('assets', 0, { alternative: 'Erstes Bild' })
            .create()

        expect(posted[1].fields).toMatchObject({
            'data[tt_content][8][assets]': '21,22',
            'data[sys_file_reference][21][alternative]': 'Erstes Bild',
        })
    })

    it('cannot join a batch, which creates its elements on one page', async () => {
        const { posted, page } = backend({ uid: 8, pid: 2 })
        const translation = new ContentBuilder(page, CONTEXT).translate('5', 1)

        await expect(saveBatch(page, CONTEXT, [translation])).rejects.toThrow(/cannot join a batch/)
        expect(posted).toEqual([])
    })
})
