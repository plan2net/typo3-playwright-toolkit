import { describe, expect, it } from 'vitest'
import { mergeRecords, RelationSet } from '#src/builders/relations.js'

function only(rows: Record<string, Record<string, unknown>>): Record<string, unknown> {
    return Object.values(rows)[0]
}

function identifierOf(rows: Record<string, Record<string, unknown>>): string {
    return Object.keys(rows)[0]
}

describe('a file reference', () => {
    it('writes its token into the column and one row naming the owner', () => {
        const relations = new RelationSet('tt_content')
        relations.withFileReference('image', 42)

        const { columns, records } = relations.materialise({ pid: 7, sys_language_uid: 0 })

        expect(columns).toEqual({ image: identifierOf(records.sys_file_reference) })
        expect(only(records.sys_file_reference)).toEqual({
            uid_local: 42,
            pid: 7,
            tablenames: 'tt_content',
            fieldname: 'image',
            sys_language_uid: 0,
            sorting_foreign: 1,
        })
    })

    it('orders the tokens and sorting_foreign by call order', () => {
        const relations = new RelationSet('tt_content')
        relations.withFileReference('assets', 42).withFileReference('assets', 7)

        const { columns, records } = relations.materialise({ pid: 7, sys_language_uid: 0 })
        const rows = records.sys_file_reference

        const [first, second] = columns.assets.split(',')
        expect(rows[first]).toMatchObject({ uid_local: 42, sorting_foreign: 1 })
        expect(rows[second]).toMatchObject({ uid_local: 7, sorting_foreign: 2 })
    })

    it('adds the extra fields to every row it writes', () => {
        const relations = new RelationSet('tt_content')
        relations.withFileReferences('assets', [42, 7], { alternative: 'A logo' })

        const { records } = relations.materialise({ pid: 7, sys_language_uid: 0 })

        expect(Object.values(records.sys_file_reference)).toEqual([
            expect.objectContaining({ uid_local: 42, alternative: 'A logo' }),
            expect.objectContaining({ uid_local: 7, alternative: 'A logo' }),
        ])
    })

    // An index-signature type cannot refuse these at compile time.
    it.each(['uid_local', 'uid_foreign', 'tablenames', 'fieldname', 'sorting_foreign'])(
        'refuses %s, which it wires itself',
        (column) => {
            expect(() =>
                new RelationSet('tt_content').withFileReference('image', 42, { [column]: 1 }),
            ).toThrow(column)
        },
    )
})

describe('a child record', () => {
    it('writes its token into the column and its row into its own table', () => {
        const relations = new RelationSet('tt_content')
        relations.withChild('items', 'tx_demo_item', (item) => item.withField('title', 'First'))

        const { columns, records } = relations.materialise({ pid: 7, sys_language_uid: 0 })

        expect(columns.items).toBe(identifierOf(records.tx_demo_item))
        expect(only(records.tx_demo_item)).toEqual({
            pid: 7,
            sys_language_uid: 0,
            title: 'First',
        })
    })

    it('writes one row per item, in the order the items are given', () => {
        const relations = new RelationSet('tt_content')
        relations.withChildren('items', 'tx_demo_item', ['First', 'Second'], (item, title) =>
            item.withField('title', title),
        )

        const { columns, records } = relations.materialise({ pid: 7, sys_language_uid: 0 })
        const rows = records.tx_demo_item

        expect(columns.items.split(',').map((token) => rows[token].title)).toEqual([
            'First',
            'Second',
        ])
    })

    it('names the child table on a reference the child carries, not the element', () => {
        const relations = new RelationSet('tt_content')
        relations.withChild('items', 'tx_demo_item', (item) => item.withFileReference('image', 42))

        const { records } = relations.materialise({ pid: 7, sys_language_uid: 0 })

        expect(only(records.sys_file_reference)).toMatchObject({
            uid_local: 42,
            tablenames: 'tx_demo_item',
            fieldname: 'image',
        })
    })

    it('lets a child carry children of its own', () => {
        const relations = new RelationSet('tt_content')
        relations.withChild('items', 'tx_demo_item', (item) =>
            item.withChild('links', 'tx_demo_link', (link) => link.withField('url', '/a')),
        )

        const { records } = relations.materialise({ pid: 7, sys_language_uid: 0 })

        expect(only(records.tx_demo_link)).toMatchObject({ url: '/a', pid: 7 })
        expect(only(records.tx_demo_item).links).toBe(identifierOf(records.tx_demo_link))
    })

    it('passes a pid a child sets on to that child’s own records', () => {
        const relations = new RelationSet('tt_content')
        relations.withChild('items', 'tx_demo_item', (item) =>
            item
                .withField('pid', 'NEWpage')
                .withChild('links', 'tx_demo_link', (link) => link.withField('url', '/a')),
        )

        const { records } = relations.materialise({ pid: 7, sys_language_uid: 0 })

        expect(only(records.tx_demo_item)).toMatchObject({ pid: 'NEWpage' })
        expect(only(records.tx_demo_link)).toMatchObject({ pid: 'NEWpage' })
    })
})

// Rows of a second table are written and then never found.
describe('one column, one relation', () => {
    it('refuses a second table on a column already holding children', () => {
        const relations = new RelationSet('tt_content')
        relations.withChild('items', 'tx_demo_item', (item) => item.withField('title', 'First'))

        expect(() =>
            relations.withChild('items', 'tx_other_item', (item) =>
                item.withField('title', 'Second'),
            ),
        ).toThrow(/items/)
    })

    it('refuses a file reference on a column already holding children', () => {
        const relations = new RelationSet('tt_content')
        relations.withChild('items', 'tx_demo_item', (item) => item.withField('title', 'First'))

        expect(() => relations.withFileReference('items', 42)).toThrow(/items/)
    })

    // The silent winner would otherwise decide what the test builds.
    it('refuses withField on a column a relation already holds', () => {
        const relations = new RelationSet('tt_content')

        expect(() =>
            relations.withChild('items', 'tx_demo_item', (item) =>
                item.withFileReference('image', 42).withField('image', 'something'),
            ),
        ).toThrow(/image/)
    })

    it('refuses a relation on a column withField already set', () => {
        const relations = new RelationSet('tt_content')

        expect(() =>
            relations.withChild('items', 'tx_demo_item', (item) =>
                item.withField('image', 'something').withFileReference('image', 42),
            ),
        ).toThrow(/image/)
    })
})

describe('merging two sets of records', () => {
    it('refuses an identifier both sides claim', () => {
        const into = { tt_content: { NEWsame: { header: 'First' } } }

        expect(() => mergeRecords(into, { tt_content: { NEWsame: { header: 'Second' } } })).toThrow(
            /tt_content/,
        )
    })
})
