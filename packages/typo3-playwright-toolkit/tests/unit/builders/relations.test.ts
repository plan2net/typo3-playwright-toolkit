import { describe, expect, it } from 'vitest'
import { RelationSet } from '#src/builders/relations.js'

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
})
