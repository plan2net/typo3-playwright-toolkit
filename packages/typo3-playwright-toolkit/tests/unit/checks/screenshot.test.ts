import { describe, expect, it } from 'vitest'
import { buildHideStyles, resolveScreenshotTarget, hiddenSelectors } from '#src/checks/screenshot.js'

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
})
