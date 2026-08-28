import { describe, expect, it } from 'vitest'
import { flexForm } from '#src/builders/fields.js'

describe('flexForm', () => {
    // A structure with one sheet calls it sDEF; a test should not have to know that.
    it('puts plain values on the default sheet', () => {
        expect(flexForm({ 'settings.limit': 10 })).toEqual({
            data: { sDEF: { lDEF: { 'settings.limit': { vDEF: 10 } } } },
        })
    })

    // A structure with named sheets has no sDEF, and a value sent to a sheet it
    // does not know is stored but never read.
    it('nests values under a named sheet', () => {
        expect(flexForm({ pagination: { 'settings.itemsPerPage': 25 } })).toEqual({
            data: { pagination: { lDEF: { 'settings.itemsPerPage': { vDEF: 25 } } } },
        })
    })

    // Most stored values carry more than one sheet, so one call has to build them all.
    it('builds several sheets in one value', () => {
        expect(
            flexForm({
                sDEF: { 'settings.orderBy': 'title' },
                sFilter: { 'settings.categories': '1,2' },
            }),
        ).toEqual({
            data: {
                sDEF: { lDEF: { 'settings.orderBy': { vDEF: 'title' } } },
                sFilter: { lDEF: { 'settings.categories': { vDEF: '1,2' } } },
            },
        })
    })
})
