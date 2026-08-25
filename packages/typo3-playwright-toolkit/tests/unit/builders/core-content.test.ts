import { describe, expect, it } from 'vitest'
import {
    BulletsContent,
    coreContentTypes,
    HtmlContent,
    ImageContent,
    MENU_TYPES,
    MenuContent,
    ShortcutContent,
    TableContent,
    TextContent,
    TextmediaContent,
    UploadsContent,
} from '#src/builders/core-content.js'
import { createContent, registerContentTypes } from '#src/builders/content-factory.js'
import type { ContentBuilderInterface, ContentFields } from '#src/types/content-builder.js'
import type { RecordDataMap } from '#src/http/record-edit.js'

describe('the shipped core CTypes', () => {
    it('covers every core CType a stock TYPO3 install registers', () => {
        const shipped = Object.keys(coreContentTypes())

        for (const cType of [
            'header',
            'text',
            'textmedia',
            'textpic',
            'image',
            'bullets',
            'table',
            'uploads',
            'html',
            'div',
            'shortcut',
            ...MENU_TYPES,
        ]) {
            expect(shipped).toContain(cType)
        }
    })

    it('writes its own CType into the fields', () => {
        for (const [cType, Constructor] of Object.entries(coreContentTypes())) {
            expect(new Constructor().getFields().CType).toBe(cType)
        }
    })

    it('is available without the consumer registering anything', () => {
        registerContentTypes({})

        expect(createContent('textmedia')).toBeInstanceOf(TextmediaContent)
    })
})

describe('the shared header columns', () => {
    it('uses the TCA column names', () => {
        const fields = new TextContent()
            .withHeader('Title')
            .withSubheader('Sub')
            .withHeaderLayout(2)
            .withHeaderLink('t3://page?uid=1')
            .getFields()

        expect(fields).toMatchObject({
            header: 'Title',
            subheader: 'Sub',
            header_layout: 2,
            header_link: 't3://page?uid=1',
        })
    })

    it('takes any other column through withField', () => {
        expect(new TextContent().withField('space_before_class', 'large').getFields()).toMatchObject({
            space_before_class: 'large',
        })
    })
})

describe('bodytext-shaped types', () => {
    it('puts one bullet per line', () => {
        expect(new BulletsContent().withItems(['one', 'two']).getFields().bodytext).toBe('one\ntwo')
    })

    it('joins table rows with the delimiter and counts the columns', () => {
        const fields = new TableContent().withRows([['a', 'b'], ['c', 'd']]).getFields()

        expect(fields.bodytext).toBe('a|b\nc|d')
        expect(fields.cols).toBe(2)
    })

    it('honours a table delimiter set through withField', () => {
        const fields = new TableContent()
            .withField('table_delimiter', 59)
            .withRows([['a', 'b']])
            .getFields()

        expect(fields.bodytext).toBe('a;b')
    })

    it('keeps html verbatim', () => {
        expect(new HtmlContent().withHtml('<b>x</b>').getFields().bodytext).toBe('<b>x</b>')
    })

    it('joins shortcut records', () => {
        expect(new ShortcutContent().withRecords(['tt_content_5', 'tt_content_9']).getFields().records).toBe(
            'tt_content_5,tt_content_9',
        )
    })
})

function referenceKey(builder: { getAdditionalRecords(a: string, b: string): RecordDataMap }): string {
    return Object.keys(builder.getAdditionalRecords('NEWcontent', '3').sys_file_reference)[0]
}

describe('FAL relations', () => {
    it('writes no reference records when no file was added', () => {
        const builder = new TextmediaContent()

        expect(builder.getAdditionalRecords('NEWcontent', '3')).toEqual({})
        expect(builder.getFields().assets).toBeUndefined()
    })

    // The element's own column lists the references. DataHandler resolves those
    // NEW ids and writes uid_foreign itself; a value we set there is ignored.
    it('lists its references on the element and leaves their parent to DataHandler', () => {
        const builder = new ImageContent().withFiles([11, 22])
        const records = builder.getAdditionalRecords('NEWcontent', '3')
        const [first, second] = Object.keys(records.sys_file_reference)

        expect(builder.getFields().image).toBe(`${first},${second}`)
        expect(records.sys_file_reference[first]).toMatchObject({
            uid_local: 11,
            tablenames: 'tt_content',
            fieldname: 'image',
            pid: '3',
            sorting_foreign: 1,
        })
        expect(records.sys_file_reference[first].uid_foreign).toBeUndefined()
        expect(records.sys_file_reference[second]).toMatchObject({ uid_local: 22, sorting_foreign: 2 })
    })

    it('uses the column each CType actually stores files in', () => {
        const textmedia = new TextmediaContent().withFile(1)
        const uploads = new UploadsContent().withFile(1)
        const image = new ImageContent().withFile(1)

        expect(textmedia.getFields().assets).toBe(referenceKey(textmedia))
        expect(uploads.getFields().media).toBe(referenceKey(uploads))
        expect(image.getFields().image).toBe(referenceKey(image))
    })

    it('sets the gallery columns', () => {
        const fields = new TextmediaContent().withColumns(3).withOrientation(8).withImageSize(600, 400).getFields()

        expect(fields).toMatchObject({ imagecols: 3, imageorient: 8, imagewidth: 600, imageheight: 400 })
    })
})

describe('menu types', () => {
    it('gives every menu_* CType a builder carrying that CType', () => {
        for (const menuType of MENU_TYPES) {
            const builder = createContent(menuType)

            expect(builder).toBeInstanceOf(MenuContent)
            expect(builder.getFields().CType).toBe(menuType)
        }
    })

    it('joins the page relation', () => {
        expect(new MenuContent('menu_pages').withPages([3, 7]).getFields().pages).toBe('3,7')
    })
})

describe('consumer overrides', () => {
    class MyTextmedia implements ContentBuilderInterface {
        readonly type = 'textmedia'

        getFields(): ContentFields {
            return { CType: 'textmedia', header: 'mine' }
        }
    }

    it('replaces a shipped builder registered under the same key', () => {
        registerContentTypes({ textmedia: MyTextmedia })

        expect(createContent('textmedia')).toBeInstanceOf(MyTextmedia)
    })

    it('leaves the other core types in place', () => {
        registerContentTypes({ textmedia: MyTextmedia })

        expect(createContent('bullets')).toBeInstanceOf(BulletsContent)
    })

    it('names what is registered when a type is missing', () => {
        registerContentTypes({})

        expect(() => createContent('my_teaser')).toThrow(/textmedia/)
    })
})
