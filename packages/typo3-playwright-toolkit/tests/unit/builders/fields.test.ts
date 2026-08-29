import { describe, expect, it } from 'vitest'
import { flexForm, imageCrop, imageCrops } from '#src/builders/fields.js'

describe('imageCrop', () => {
    it('crops the whole image when only a ratio is named', () => {
        expect(JSON.parse(imageCrop({ ratio: '16:9' }))).toEqual({
            default: {
                cropArea: { x: 0, y: 0, width: 1, height: 1 },
                selectedRatio: '16:9',
                focusArea: null,
            },
        })
    })

    it('takes an area of its own', () => {
        expect(JSON.parse(imageCrop({ area: { x: 0.1, y: 0.2, width: 0.5, height: 0.4 } }))).toEqual({
            default: {
                cropArea: { x: 0.1, y: 0.2, width: 0.5, height: 0.4 },
                selectedRatio: 'NaN',
                focusArea: null,
            },
        })
    })

    // The column holds JSON text, so a builder can hand it straight to a record.
    it('is a string', () => {
        expect(typeof imageCrop()).toBe('string')
    })
})

describe('imageCrops', () => {
    it('writes one entry per crop variant the project configured', () => {
        const crops = JSON.parse(imageCrops({ mobile: { ratio: '9:16' }, desktop: { ratio: '16:9' } }))

        expect(crops).toEqual({
            mobile: { cropArea: { x: 0, y: 0, width: 1, height: 1 }, selectedRatio: '9:16', focusArea: null },
            desktop: { cropArea: { x: 0, y: 0, width: 1, height: 1 }, selectedRatio: '16:9', focusArea: null },
        })
    })
})

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
