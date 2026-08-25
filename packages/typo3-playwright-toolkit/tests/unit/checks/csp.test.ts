import { beforeEach, describe, expect, it } from 'vitest'
import { setToolkitConfig, type ToolkitConfig } from '#src/config.js'
import {
    CspVerifier,
    describeViolations,
    isExpectedDocumentOrigin,
    policyIssues,
    type CspViolation,
    type DocumentPolicy,
} from '#src/checks/csp.js'

function configWith(csp?: ToolkitConfig['csp']): ToolkitConfig {
    return {
        testingURL: 'https://example.test',
        contentTypes: {},
        paths: {
            consumerRoot: '/srv/project',
            stateDir: '/srv/project/.test-state',
            sessionDir: '/srv/project/var/session',
        },
        csp,
    }
}

function violation(overrides: Partial<CspViolation> = {}): CspViolation {
    return {
        blockedUri: 'https://cdn.example.test/x.js',
        columnNumber: 1,
        disposition: 'report',
        documentUri: 'https://example.test/page',
        effectiveDirective: 'script-src',
        lineNumber: 2,
        originalPolicy: "script-src 'self'",
        sample: '',
        sourceFile: 'https://example.test/app.js',
        violatedDirective: 'script-src',
        ...overrides,
    }
}

function policy(overrides: Partial<DocumentPolicy> = {}): DocumentPolicy {
    return {
        enforced: null,
        reportOnly: "script-src 'self'",
        url: 'https://example.test/page',
        ...overrides,
    }
}

beforeEach(() => {
    setToolkitConfig(configWith())
})

describe('isExpectedDocumentOrigin', () => {
    it('accepts a document from the expected origin', () => {
        expect(isExpectedDocumentOrigin('https://example.test/page', 'https://example.test')).toBe(true)
    })

    it('rejects another origin', () => {
        expect(isExpectedDocumentOrigin('https://other.test/page', 'https://example.test')).toBe(false)
    })

    // A sandboxed iframe reports "null" as its origin, which must never match.
    it('rejects an opaque origin', () => {
        expect(isExpectedDocumentOrigin('about:blank', 'null')).toBe(false)
    })

    it('rejects an empty or unparseable uri', () => {
        expect(isExpectedDocumentOrigin('', 'https://example.test')).toBe(false)
        expect(isExpectedDocumentOrigin('   ', 'https://example.test')).toBe(false)
        expect(isExpectedDocumentOrigin('not a url', 'https://example.test')).toBe(false)
    })
})

describe('policyIssues', () => {
    // A run that saw no document proves nothing: without this it passes vacuously.
    it('reports when documents were requested but none answered', () => {
        const issues = policyIssues([], ['https://example.test/page'], 'any', 'https://example.test')

        expect(issues).toHaveLength(1)
        expect(issues[0]).toContain('https://example.test/page')
    })

    it('is quiet when nothing was requested at all', () => {
        expect(policyIssues([], [], 'any', 'https://example.test')).toEqual([])
    })

    describe('mode any', () => {
        it('accepts either header', () => {
            expect(policyIssues([policy()], [], 'any', 'o')).toEqual([])
            expect(
                policyIssues([policy({ enforced: "script-src 'self'", reportOnly: null })], [], 'any', 'o'),
            ).toEqual([])
        })

        it('reports a document with no policy at all', () => {
            const issues = policyIssues([policy({ enforced: null, reportOnly: null })], [], 'any', 'o')

            expect(issues).toHaveLength(1)
            expect(issues[0]).toContain('no Content-Security-Policy')
        })

        it('treats a blank header as absent', () => {
            const issues = policyIssues([policy({ reportOnly: '   ' })], [], 'any', 'o')

            expect(issues).toHaveLength(1)
        })
    })

    describe('mode report-only', () => {
        it('requires the report-only header', () => {
            expect(policyIssues([policy()], [], 'report-only', 'o')).toEqual([])
            expect(policyIssues([policy({ reportOnly: null })], [], 'report-only', 'o')).toHaveLength(1)
        })

        // A project deliberately not enforcing wants to know if one appears.
        it('refuses an enforcing header', () => {
            const issues = policyIssues([policy({ enforced: "script-src 'self'" })], [], 'report-only', 'o')

            expect(issues).toHaveLength(1)
            expect(issues[0]).toContain('enforcing')
        })
    })

    describe('mode enforced', () => {
        it('requires the enforcing header', () => {
            expect(
                policyIssues([policy({ enforced: "script-src 'self'" })], [], 'enforced', 'o'),
            ).toEqual([])
            expect(policyIssues([policy()], [], 'enforced', 'o')).toHaveLength(1)
        })

        // Enforcing a baseline while trialling a stricter policy is normal.
        it('allows a report-only header alongside', () => {
            const both = policy({ enforced: "script-src 'self'", reportOnly: "script-src 'none'" })

            expect(policyIssues([both], [], 'enforced', 'o')).toEqual([])
        })
    })

    it('reports every offending document', () => {
        const issues = policyIssues(
            [
                policy({ url: 'https://example.test/a', reportOnly: null }),
                policy({ url: 'https://example.test/b' }),
                policy({ url: 'https://example.test/c', reportOnly: null }),
            ],
            [],
            'report-only',
            'o',
        )

        expect(issues).toHaveLength(2)
        expect(issues.join(' ')).toContain('/a')
        expect(issues.join(' ')).toContain('/c')
    })
})

describe('describeViolations', () => {
    it('says nothing when there are none', () => {
        expect(describeViolations([])).toEqual([])
    })

    it('groups by directive and counts them', () => {
        const lines = describeViolations([
            violation({ effectiveDirective: 'script-src' }),
            violation({ effectiveDirective: 'script-src', blockedUri: 'https://cdn.example.test/y.js' }),
            violation({ effectiveDirective: 'img-src' }),
        ])

        expect(lines).toHaveLength(2)
        expect(lines[0]).toContain('script-src (2)')
        expect(lines[1]).toContain('img-src (1)')
    })

    it('names the source position when there is one', () => {
        const lines = describeViolations([violation({ sourceFile: 'https://x.test/a.js', lineNumber: 7, columnNumber: 3 })])

        expect(lines[0]).toContain('https://x.test/a.js:7:3')
    })

    it('falls back to the document when the source is unknown', () => {
        const lines = describeViolations([violation({ sourceFile: '' })])

        expect(lines[0]).toContain('https://example.test/page')
    })

    it('includes an inline sample when axe-style truncation gave one', () => {
        expect(describeViolations([violation({ sample: 'alert(1)' })])[0]).toContain('alert(1)')
    })

    it('names an unknown blocked uri rather than printing nothing', () => {
        expect(describeViolations([violation({ blockedUri: '' })])[0]).toContain('unknown')
    })
})

/**
 * Stands in for a BrowserContext. `flushed` is a method rather than a property:
 * spreading a number would hand the test a copy taken before anything ran.
 */
function fakeContext() {
    const bindings: Record<string, (source: unknown, violation: CspViolation) => void> = {}
    const listeners: Record<string, ((event: unknown) => void)[]> = {}
    let flushed = 0

    return {
        bindings,
        listeners,
        flushed: () => flushed,
        async exposeBinding(name: string, fn: (source: unknown, v: CspViolation) => void) {
            bindings[name] = fn
        },
        async addInitScript() {},
        on(event: string, listener: (event: unknown) => void) {
            listeners[event] = [...(listeners[event] ?? []), listener]
        },
        pages() {
            return [
                {
                    isClosed: () => false,
                    evaluate: async () => {
                        flushed++
                    },
                },
                {
                    isClosed: () => true,
                    evaluate: async () => {
                        throw new Error('a closed page was evaluated')
                    },
                },
            ]
        },
    }
}

const WITH_POLICY = { 'content-security-policy': "script-src 'self'" }

function documentResponse(url: string, headers: Record<string, string>, status = 200) {
    return {
        request: () => ({ resourceType: () => 'document' }),
        headers: () => ({ 'content-type': 'text/html; charset=utf-8', ...headers }),
        status: () => status,
        url: () => url,
    }
}

describe('CspVerifier', () => {
    // The listeners live in install(), so without it there is nothing to report —
    // and a silent pass for a page that was never watched is worse than an error.
    it('refuses to report on a page it never watched', async () => {
        const verifier = new CspVerifier(fakeContext() as never)

        await expect(verifier.assertNoViolations()).rejects.toThrow(/install\(\)/)
    })

    it('collects a same-origin violation and fails naming its directive', async () => {
        const context = fakeContext()
        const verifier = new CspVerifier(context as never)
        await verifier.install()

        context.bindings.__playwrightReportCspViolation(null, violation())

        await expect(verifier.assertNoViolations()).rejects.toThrow(/script-src/)
    })

    // Third-party frames report their own violations; they are not ours to fail on.
    it('ignores a violation from another origin', async () => {
        const context = fakeContext()
        const verifier = new CspVerifier(context as never)
        await verifier.install()

        context.bindings.__playwrightReportCspViolation(
            null,
            violation({ documentUri: 'https://widget.other.test/embed' }),
        )
        context.listeners.response[0](documentResponse('https://example.test/page', WITH_POLICY))

        await expect(verifier.assertNoViolations()).resolves.toBeUndefined()
    })

    it('passes when a document carried a policy and nothing was blocked', async () => {
        const context = fakeContext()
        const verifier = new CspVerifier(context as never)
        await verifier.install()

        context.listeners.response[0](documentResponse('https://example.test/page', WITH_POLICY))

        await expect(verifier.assertNoViolations()).resolves.toBeUndefined()
    })

    it('only records same-origin html document responses', async () => {
        const context = fakeContext()
        const verifier = new CspVerifier(context as never)
        await verifier.install()

        const onResponse = context.listeners.response[0]
        onResponse(documentResponse('https://other.test/page', {}))
        onResponse(documentResponse('https://example.test/x.json', { 'content-type': 'application/json' }))
        onResponse(documentResponse('https://example.test/missing', {}, 404))

        // None counted, so no policy is asserted and nothing fails.
        await expect(verifier.assertNoViolations()).resolves.toBeUndefined()
    })

    // exposeBinding deliveries are queued, so asserting without a round-trip can
    // miss a violation that has already happened in the page.
    it('flushes the open pages before asserting', async () => {
        const context = fakeContext()
        const verifier = new CspVerifier(context as never)
        await verifier.install()

        await verifier.assertNoViolations()

        expect(context.flushed()).toBe(1)
    })

    it('honours the configured mode', async () => {
        setToolkitConfig(configWith({ mode: 'report-only' }))
        const context = fakeContext()
        const verifier = new CspVerifier(context as never)
        await verifier.install()

        context.listeners.response[0](documentResponse('https://example.test/page', WITH_POLICY))

        await expect(verifier.assertNoViolations()).rejects.toThrow(/enforcing/)
    })

    it('takes the expected origin from testingURL', async () => {
        const context = fakeContext()
        const verifier = new CspVerifier(context as never)
        await verifier.install()

        context.bindings.__playwrightReportCspViolation(
            null,
            violation({ documentUri: 'https://example.test/anything' }),
        )

        await expect(verifier.assertNoViolations()).rejects.toThrow(/script-src/)
    })

    it('lets a caller override the origin', async () => {
        const context = fakeContext()
        const verifier = new CspVerifier(context as never, { expectedOrigin: 'https://staging.test' })
        await verifier.install()

        context.bindings.__playwrightReportCspViolation(
            null,
            violation({ documentUri: 'https://staging.test/page' }),
        )

        await expect(verifier.assertNoViolations()).rejects.toThrow(/script-src/)
    })

    it('attaches a report when it fails and one is offered', async () => {
        const context = fakeContext()
        const verifier = new CspVerifier(context as never)
        await verifier.install()
        context.bindings.__playwrightReportCspViolation(null, violation())

        const attached: { name: string; body: Buffer }[] = []
        const testInfo = {
            attach: async (name: string, options: { body: Buffer }) => {
                attached.push({ name, body: options.body })
            },
        }

        await expect(verifier.assertNoViolations(testInfo as never)).rejects.toThrow()

        expect(attached).toHaveLength(1)
        expect(JSON.parse(attached[0].body.toString()).violations).toHaveLength(1)
    })

    it('attaches nothing when it passes', async () => {
        const context = fakeContext()
        const verifier = new CspVerifier(context as never)
        await verifier.install()

        const attached: string[] = []
        const testInfo = { attach: async (name: string) => void attached.push(name) }

        await verifier.assertNoViolations(testInfo as never)

        expect(attached).toEqual([])
    })
})
