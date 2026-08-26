import { describe, expect, it } from 'vitest'
import { TEST_ID_HEADER, TEST_ID_PATTERN, browserHeaders, generateTestId, toolkitHeaders } from '#src/contract.js'
import type { ToolkitConfig } from '#src/config.js'

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

// An empty header would still read as a toolkit request on the way in.
describe('headers with an empty test ID', () => {
    const config = {
        testingURL: 'https://example-testing.test',
        paths: { consumerRoot: '/srv', stateDir: '/srv/.test-state', sessionDir: '/srv/var/session' },
    } as ToolkitConfig

    it('browserHeaders omits the test-ID header', () => {
        expect(browserHeaders('')).toEqual({})
    })

    it('toolkitHeaders carries only the secret', () => {
        process.env.PLAYWRIGHT_TOOLKIT_SECRET = 'shh'
        try {
            expect(toolkitHeaders(config, '')).toEqual({ 'X-Playwright-Toolkit-Secret': 'shh' })
        } finally {
            delete process.env.PLAYWRIGHT_TOOLKIT_SECRET
        }
    })
})
