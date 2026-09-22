import { describe, expect, it, vi } from 'vitest'
import { setToolkitConfig } from '#src/config.js'
import {
    buildHideStyles,
    resolveScreenshotTarget,
    hiddenSelectors,
    comparisonOptions,
    warnAboutUndecodedImages,
    expectScreenshot,
} from '#src/checks/screenshot.js'

describe('resolveScreenshotTarget', () => {
    const marker = { name: 'the element' }
    const page = { locator: (selector: string) => ({ ...marker, selector }) }

    it('shoots the whole page when no selector is given', () => {
        const resolved = resolveScreenshotTarget(page as never)

        expect(resolved.shot).toBe(page)
        expect(resolved.wholePage).toBe(true)
    })

    it('shoots the element a selector names', () => {
        const resolved = resolveScreenshotTarget(page as never, '.accordion')

        expect(resolved.shot).toMatchObject({ name: 'the element', selector: '.accordion' })
        expect(resolved.wholePage).toBe(false)
    })

    // A locator is already one element, so fullPage would be wrong for it.
    it('treats a locator as an element, not a page', () => {
        const locator = { page: () => page, locator: (selector: string) => ({ ...marker, selector }) }

        expect(resolveScreenshotTarget(locator as never).wholePage).toBe(false)
    })
})

describe('buildHideStyles', () => {
    it('returns empty string for no selectors', () => {
        expect(buildHideStyles([])).toBe('')
    })

    it('hides each selector with visibility:hidden', () => {
        const css = buildHideStyles(['.header--main', '.cookie-banner'])
        expect(css).toContain('.header--main')
        expect(css).toContain('.cookie-banner')
        expect(css).toMatch(/visibility:\s*hidden/)
    })

    it('joins multiple selectors into one rule group', () => {
        const css = buildHideStyles(['.a', '.b'])
        expect(css).toContain('.a, .b')
    })
})

describe('hiddenSelectors', () => {
    it('uses the configured list when the call says nothing', () => {
        expect(hiddenSelectors(['.header'], undefined)).toEqual(['.header'])
    })

    // A shot *of* the element the config hides needs a way out.
    it('lets a call hide nothing', () => {
        expect(hiddenSelectors(['.header'], [])).toEqual([])
    })

    it('lets a call name its own selectors', () => {
        expect(hiddenSelectors(['.header'], ['.banner'])).toEqual(['.banner'])
    })

    it('is empty when neither names anything', () => {
        expect(hiddenSelectors(undefined, undefined)).toEqual([])
    })

    it('adds what a call hides on top of the configured list', () => {
        expect(hiddenSelectors(['.header'], undefined, ['.banner'])).toEqual(['.header', '.banner'])
    })
})

describe('comparisonOptions', () => {
    // Repeating it per call would override a value the project set the Playwright way.
    it('leaves the tolerance to the Playwright config', () => {
        setToolkitConfig({
            testingURL: 'https://example-testing.test',
            paths: {
                consumerRoot: '/srv/project',
                stateDir: '/srv/project/.test-state',
                sessionDir: '/srv/project/var/session',
            },
            screenshot: { threshold: 0.3 },
        })

        expect(comparisonOptions(false, {})).not.toHaveProperty('threshold')
    })

    // Playwright lays the call over the config and then keeps the smaller of the two
    // allowances, so the configured count has to be cleared or it caps the ratio.
    it('clears the configured pixel count when the call asks for a ratio', () => {
        const options = comparisonOptions(false, { maxDiffPixelRatio: 0.03 })

        expect(Object.keys(options)).toContain('maxDiffPixels')
        expect(options.maxDiffPixels).toBeUndefined()
    })

    it('leaves the configured pixel count alone for a call that says nothing', () => {
        expect(Object.keys(comparisonOptions(false, {}))).not.toContain('maxDiffPixels')
    })
})

// The comparison needs the Playwright runner and throws here, which is the path
// that matters: however the shot ends, the page is left as it was.
describe('the styles expectScreenshot injects', () => {
    function stubPage(): { page: unknown; added: string[]; removed: string[] } {
        const added: string[] = []
        const removed: string[] = []
        const page = {
            addStyleTag: async ({ content }: { content: string }) => {
                added.push(content)

                return { evaluate: async () => removed.push(content) }
            },
            evaluate: async () => [],
            locator: () => ({ page: () => page }),
        }

        return { page, added, removed }
    }

    it('are taken off again when the shot is over', async () => {
        setToolkitConfig({
            testingURL: 'https://example-testing.test',
            paths: {
                consumerRoot: '/srv/project',
                stateDir: '/srv/project/.test-state',
                sessionDir: '/srv/project/var/session',
            },
            hideBeforeScreenshot: ['.cookie-banner'],
        })
        const { page, added, removed } = stubPage()

        await expect(expectScreenshot(page as never, 'a-page')).rejects.toThrow()

        expect(added).toHaveLength(2)
        expect(removed).toEqual(added)
    })

    it('hide what the config hides plus what the call adds', async () => {
        setToolkitConfig({
            testingURL: 'https://example-testing.test',
            paths: {
                consumerRoot: '/srv/project',
                stateDir: '/srv/project/.test-state',
                sessionDir: '/srv/project/var/session',
            },
            hideBeforeScreenshot: ['.cookie-banner'],
        })
        const { page, added } = stubPage()

        await expect(expectScreenshot(page as never, 'a-page', { hideAlso: ['.chat'] })).rejects.toThrow()

        expect(added.join('\n')).toContain('.cookie-banner, .chat')
    })
})

describe('a page that moved on under the shot', () => {
    // The tag went with the old document; removing it must not replace the
    // failure the caller needs to read.
    it('keeps the comparison failure rather than the detached tag', async () => {
        setToolkitConfig({
            testingURL: 'https://example-testing.test',
            paths: {
                consumerRoot: '/srv/project',
                stateDir: '/srv/project/.test-state',
                sessionDir: '/srv/project/var/session',
            },
        })
        const page = {
            addStyleTag: async () => ({
                evaluate: () => Promise.reject(new Error('Element is not attached to the DOM')),
            }),
            evaluate: async () => [],
            locator: () => ({ page: () => page }),
        }

        await expect(expectScreenshot(page as never, 'a-page')).rejects.toThrow(/toHaveScreenshot/)
    })
})

describe('warnAboutUndecodedImages', () => {
    it('names every image that did not decode, and how long it waited', () => {
        const said: string[] = []
        const warn = vi.spyOn(console, 'warn').mockImplementation((message) => said.push(String(message)))

        warnAboutUndecodedImages(['https://example.test/a.avif', 'https://example.test/b.avif'], 15000)
        warn.mockRestore()

        expect(said.join('\n')).toContain('https://example.test/a.avif')
        expect(said.join('\n')).toContain('https://example.test/b.avif')
        expect(said.join('\n')).toContain('15000')
    })
})
