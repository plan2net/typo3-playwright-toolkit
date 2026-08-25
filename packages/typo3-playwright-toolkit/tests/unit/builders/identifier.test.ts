import { describe, expect, it } from 'vitest'
import { newRecordIdentifier } from '#src/builders/identifier.js'

describe('newRecordIdentifier', () => {
    it('marks the record as new', () => {
        expect(newRecordIdentifier()).toMatch(/^NEW/)
    })

    it('carries only the characters TYPO3 mints itself', () => {
        for (let i = 0; i < 50; i++) {
            expect(newRecordIdentifier()).toMatch(/^NEW[0-9a-f]+$/)
        }
    })

    it('does not repeat itself', () => {
        const seen = new Set(Array.from({ length: 100 }, () => newRecordIdentifier()))

        expect(seen.size).toBe(100)
    })
})
