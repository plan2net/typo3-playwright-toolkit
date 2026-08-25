import { describe, expect, it } from 'vitest'
import { assertJsonSafe } from '#src/state/json-safe.js'

class Page {
    constructor(readonly id: string) {}
}

describe('assertJsonSafe', () => {
    it('accepts the shapes state is made of', () => {
        expect(() =>
            assertJsonSafe({
                slug: '/accordion',
                ids: [1, 2, 3],
                nested: { ok: true, missing: null },
                empty: [],
            }),
        ).not.toThrow()
    })

    it('accepts a bare value', () => {
        expect(() => assertJsonSafe('slug')).not.toThrow()
        expect(() => assertJsonSafe(null)).not.toThrow()
    })

    it('rejects undefined and shows where it sat', () => {
        expect(() => assertJsonSafe({ pages: [{ slug: undefined }] })).toThrow(/slug/)
    })

    it('rejects a function', () => {
        expect(() => assertJsonSafe({ build: () => 'x' })).toThrow(/build/)
    })

    it('rejects a bigint', () => {
        expect(() => assertJsonSafe({ id: 10n })).toThrow(/BigInt/i)
    })

    it('rejects a Date, which would come back as a string', () => {
        expect(() => assertJsonSafe({ createdAt: new Date(0) })).toThrow(/createdAt/)
    })

    it('rejects a class instance, which would lose its type', () => {
        expect(() => assertJsonSafe({ page: new Page('1') })).toThrow(/page/)
    })

    it('rejects a Map', () => {
        expect(() => assertJsonSafe({ byId: new Map() })).toThrow(/byId/)
    })

    it('rejects NaN and Infinity, which would come back as null', () => {
        expect(() => assertJsonSafe({ count: NaN })).toThrow(/count/)
        expect(() => assertJsonSafe({ count: Infinity })).toThrow(/count/)
    })

    it('rejects a cycle instead of hanging', () => {
        const looped: Record<string, unknown> = {}
        looped.self = looped

        expect(() => assertJsonSafe(looped)).toThrow(/circular/i)
    })

    it('uses the label in the message', () => {
        expect(() => assertJsonSafe({ a: undefined }, 'setup state')).toThrow(/setup state/)
    })
})
