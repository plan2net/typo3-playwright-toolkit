import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { setToolkitConfig, type ToolkitConfig } from '#src/config.js'
import {
    DEFAULT_SCAN_TAGS,
    axeSource,
    buildAxeRunInput,
    shouldScanAutomatically,
    scanAccessibility,
    shouldScanProject,
} from '#src/checks/accessibility.js'

function configWith(accessibility?: ToolkitConfig['accessibility']): ToolkitConfig {
    return {
        testingURL: 'https://example-testing.test',
        contentTypes: {},
        paths: {
            consumerRoot: '/srv/project',
            stateDir: '/srv/project/.test-state',
            sessionDir: '/srv/project/var/session',
        },
        accessibility,
    }
}

beforeEach(() => {
    setToolkitConfig(configWith())
})

afterEach(() => {
    setToolkitConfig(configWith())
})

describe('shouldScanAutomatically', () => {
    it('is off unless a consumer asks for it', () => {
        expect(shouldScanAutomatically({ status: 'passed', navigated: true })).toBe(false)
        expect(shouldScanAutomatically({ status: 'passed', navigated: true }, { auto: false })).toBe(false)
    })

    it('scans a passing test that opened a page', () => {
        expect(shouldScanAutomatically({ status: 'passed', navigated: true }, { auto: true })).toBe(true)
    })

    // A test that never navigated has nothing to scan, and axe on about:blank
    // reports no rules, which the scan then treats as a failure.
    it('skips a test that never opened a page', () => {
        expect(shouldScanAutomatically({ status: 'passed', navigated: false }, { auto: true })).toBe(false)
    })

    // The first failure is the one worth reading; a second, derived one buries it.
    it('skips a test that already failed', () => {
        expect(shouldScanAutomatically({ status: 'failed', navigated: true }, { auto: true })).toBe(false)
    })
})

describe('shouldScanProject', () => {
    // Scanning nowhere is the dangerous default: the suite stays green while
    // proving nothing. An unconfigured project list therefore means "everywhere".
    it('scans every project when none are configured', () => {
        expect(shouldScanProject('anything-at-all', undefined)).toBe(true)
        expect(shouldScanProject('anything-at-all', [])).toBe(true)
    })

    it('scans only the configured projects', () => {
        const projects = ['chromium-desktop', 'chromium-mobile']

        expect(shouldScanProject('chromium-desktop', projects)).toBe(true)
        expect(shouldScanProject('chromium-mobile', projects)).toBe(true)
        expect(shouldScanProject('firefox-desktop', projects)).toBe(false)
    })
})

describe('buildAxeRunInput', () => {
    it('scans the whole document when neither include nor exclude is given', () => {
        const input = buildAxeRunInput({}, undefined)

        expect(input.include).toBeNull()
        expect(input.exclude).toBeNull()
    })

    it('passes include and exclude through', () => {
        const input = buildAxeRunInput({ include: '.card', exclude: '.ad' }, undefined)

        expect(input.include).toBe('.card')
        expect(input.exclude).toBe('.ad')
    })

    it('uses the WCAG tag set by default', () => {
        expect(buildAxeRunInput({}, undefined).tags).toEqual(DEFAULT_SCAN_TAGS)
    })

    it('lets a project narrow or widen the tags', () => {
        expect(buildAxeRunInput({}, { tags: ['wcag2a'] }).tags).toEqual(['wcag2a'])
    })

    it('lets a single scan override the tags', () => {
        expect(buildAxeRunInput({ tags: ['best-practice'] }, { tags: ['wcag2a'] }).tags).toEqual([
            'best-practice',
        ])
    })

    /**
     * axe evaluates a fragment's headings against the WHOLE document's hierarchy,
     * so a component starting at h3 fails on out-of-scope context — the fixture
     * page's h1. Document heading structure is asserted by whole-page scans.
     */
    it('drops heading-order for a scoped scan', () => {
        expect(buildAxeRunInput({ include: '.card' }, undefined).disabledRules).toContain('heading-order')
    })

    it('keeps heading-order for a whole-page scan', () => {
        expect(buildAxeRunInput({}, undefined).disabledRules).not.toContain('heading-order')
    })

    it('keeps heading-order for an exclude-only scan, which still sees the document', () => {
        expect(buildAxeRunInput({ exclude: '.ad' }, undefined).disabledRules).not.toContain('heading-order')
    })

    it('merges the scan, the project and the scoped exemption', () => {
        const input = buildAxeRunInput({ include: '.card', disabledRules: ['color-contrast'] }, {
            disabledRules: ['region'],
        })

        expect(input.disabledRules.sort()).toEqual(['color-contrast', 'heading-order', 'region'])
    })

    it('does not repeat a rule the caller already disabled', () => {
        const input = buildAxeRunInput({ include: '.card', disabledRules: ['heading-order'] }, undefined)

        expect(input.disabledRules.filter((rule) => rule === 'heading-order')).toHaveLength(1)
    })
})

describe('axeSource', () => {
    // Resolved from this package, never from the consumer root: axe-core is our
    // dependency, so it may well not exist in the consumer's own node_modules.
    it('reads axe-core from this package and caches it', () => {
        const first = axeSource()

        expect(first).toContain('axe')
        expect(axeSource()).toBe(first)
    })
})

interface FakePage {
    evaluate: (fn: unknown, arg?: unknown) => Promise<unknown>
    calls: unknown[]
}

/** Stands in for a Playwright Page: the first evaluate probes for axe, the rest run it. */
function fakePage(results: unknown, alreadyInjected = false): FakePage {
    const calls: unknown[] = []

    return {
        calls,
        async evaluate(fn: unknown, arg?: unknown) {
            calls.push(arg ?? fn)
            if (calls.length === 1) {
                return alreadyInjected
            }
            if (typeof fn === 'string') {
                return undefined
            }

            return results
        },
    }
}

const cleanResults = { violations: [], passes: [{ id: 'region' }], incomplete: [] }

describe('scanAccessibility', () => {
    it('returns null and touches nothing when the project is not scanned', async () => {
        setToolkitConfig(configWith({ projects: ['chromium-desktop'] }))
        const page = fakePage(cleanResults)

        const results = await scanAccessibility(page as never, { projectName: 'firefox-desktop' })

        expect(results).toBeNull()
        expect(page.calls).toEqual([])
    })

    it('injects axe when the page does not have it yet', async () => {
        const page = fakePage(cleanResults, false)

        await scanAccessibility(page as never, { projectName: 'chromium-desktop' })

        // probe, inject, run
        expect(page.calls).toHaveLength(3)
        expect(typeof page.calls[1]).toBe('string')
    })

    it('does not inject axe twice on the same page', async () => {
        const page = fakePage(cleanResults, true)

        await scanAccessibility(page as never, { projectName: 'chromium-desktop' })

        // probe, run
        expect(page.calls).toHaveLength(2)
    })

    it('returns the results axe produced', async () => {
        const page = fakePage(cleanResults, true)

        const results = await scanAccessibility(page as never, { projectName: 'chromium-desktop' })

        expect(results).toEqual(cleanResults)
    })
})
