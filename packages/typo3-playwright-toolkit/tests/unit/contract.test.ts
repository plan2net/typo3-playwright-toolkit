import { describe, expect, it } from 'vitest'
import { TEST_ID_HEADER, TEST_ID_PATTERN, generateTestId } from '#src/contract.js'

describe('contract constants', () => {
    it('exposes the wire-contract header name', () => {
        expect(TEST_ID_HEADER).toBe('X-Playwright-Test-Id')
    })

    it('exposes the test-ID pattern', () => {
        expect(TEST_ID_PATTERN.source).toBe('^[A-Z0-9]{16}$')
    })
})

describe('generateTestId', () => {
    it('produces a value matching the contract pattern', () => {
        for (let attempt = 0; attempt < 200; attempt++) {
            const id = generateTestId()
            expect(id).toMatch(TEST_ID_PATTERN)
        }
    })

    it('produces a 16-character id', () => {
        expect(generateTestId()).toHaveLength(16)
    })
})
