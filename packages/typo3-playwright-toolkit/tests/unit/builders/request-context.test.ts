import { beforeEach, describe, expect, it } from 'vitest'
import { requireTestId, resolveRequestContext, type RequestContextSource } from '#src/builders/request-context.js'
import { setToolkitConfig } from '#src/config.js'

function pageAt(url: string, ambient: { page?: string; context?: string } = {}): RequestContextSource {
    return {
        url: () => url,
        context: () => ({ testId: ambient.context }),
        testId: ambient.page,
    } as RequestContextSource
}

beforeEach(() => {
    setToolkitConfig({
        testingURL: 'https://example-testing.test',
        contentTypes: {},
        paths: {
            consumerRoot: '/srv/project',
            stateDir: '/srv/project/.test-state',
            sessionDir: '/srv/project/var/session',
        },
    })
})

describe('resolveRequestContext', () => {
    // The request carries the API secret, so wherever the page has navigated to
    // never decides where the builder posts it.
    it.each([
        ['a page on the site', 'https://example-testing.test/typo3/module/web/layout'],
        ['a blank page', 'about:blank'],
        ['a data url mentioning http', 'data:text/html,<a href="http://x/typo3">'],
        ['a page that wandered off-site', 'https://evil.test/typo3/module'],
    ])('posts to the configured testing url from %s', (_case, url) => {
        const resolved = resolveRequestContext(pageAt(url), { testId: 'ABCD1234EFGH5678' })

        expect(resolved.baseUrl).toBe('https://example-testing.test')
    })

    // The session endpoint reports it; a builder used outside a scenario has none.
    it('assumes the stock backend path when none is given', () => {
        const resolved = resolveRequestContext(pageAt('https://site.test'), { testId: 'ABCD1234EFGH5678' })

        expect(resolved.backendPath).toBe('/typo3')
    })

    it('prefers an explicit backend path', () => {
        const resolved = resolveRequestContext(pageAt('https://site.test'), {
            testId: 'ABCD1234EFGH5678',
            backendPath: '/admin',
        })

        expect(resolved.backendPath).toBe('/admin')
    })

    it('prefers an explicit base url', () => {
        const resolved = resolveRequestContext(pageAt('https://site.test/typo3'), {
            testId: 'ABCD1234EFGH5678',
            baseUrl: 'https://given.test',
        })

        expect(resolved.baseUrl).toBe('https://given.test')
    })

    it('reads the test id off the page when none is given', () => {
        const resolved = resolveRequestContext(pageAt('https://site.test/typo3', { page: 'PAGE1234PAGE1234' }))

        expect(resolved.testId).toBe('PAGE1234PAGE1234')
    })

    it('falls back to the test id on the context', () => {
        const resolved = resolveRequestContext(pageAt('https://site.test/typo3', { context: 'CTX41234CTX41234' }))

        expect(resolved.testId).toBe('CTX41234CTX41234')
    })

    it('prefers an explicit test id over the ambient one', () => {
        const resolved = resolveRequestContext(pageAt('https://site.test/typo3', { page: 'PAGE1234PAGE1234' }), {
            testId: 'ABCD1234EFGH5678',
        })

        expect(resolved.testId).toBe('ABCD1234EFGH5678')
    })

    it('refuses to guess when there is no test id at all', () => {
        expect(() => resolveRequestContext(pageAt('https://site.test/typo3'))).toThrow(/test ID/)
    })

    it('never silently uses an empty test id, which would hit the base database', () => {
        expect(() => resolveRequestContext(pageAt('https://site.test/typo3', { page: '' }))).toThrow(/test ID/)
    })
})

describe('requireTestId', () => {
    it('returns the ambient id', () => {
        expect(requireTestId(pageAt('https://site.test/typo3', { context: 'CTX41234CTX41234' }))).toBe(
            'CTX41234CTX41234',
        )
    })

    it('prefers an explicit id', () => {
        expect(requireTestId(pageAt('https://site.test/typo3', { context: 'CTX41234CTX41234' }), 'ABCD1234EFGH5678')).toBe(
            'ABCD1234EFGH5678',
        )
    })

    it('throws rather than returning an empty id', () => {
        expect(() => requireTestId(pageAt('https://site.test/typo3'))).toThrow(/test ID/)
    })
})

describe('replay mode', () => {
    beforeEach(() => {
        setToolkitConfig({
            testingURL: 'https://example-testing.test',
            replay: true,
            paths: {
                consumerRoot: '/srv/project',
                stateDir: '/srv/project/.test-state',
                sessionDir: '/srv/project/var/session',
            },
        })
    })

    it('requireTestId answers empty instead of throwing', () => {
        expect(requireTestId(pageAt('https://site.test/typo3'))).toBe('')
    })

    it('resolveRequestContext resolves without a test id', () => {
        const resolved = resolveRequestContext(pageAt('https://site.test/typo3'))

        expect(resolved.testId).toBe('')
        expect(resolved.baseUrl).toBe('https://example-testing.test')
    })
})
