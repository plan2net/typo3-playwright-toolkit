import { expect, Locator, Page, PageAssertionsToHaveScreenshotOptions } from '@playwright/test'
import { getToolkitConfig } from '../config.js'

const FREEZE_STYLES = `* {
    animation-duration: 0s !important;
    transition-duration: 0s !important;
    transition-delay: 0s !important;
    contain-intrinsic-size: none !important;
    content-visibility: visible !important;
}`

export function buildHideStyles(selectors: string[]): string {
    if (selectors.length === 0) {
        return ''
    }

    return `${selectors.join(', ')} { visibility: hidden !important; }`
}

/**
 * takeScreenshot already waits; call this when you interact and then assert
 * without one, such as an accessibility scan after opening an accordion.
 */
export async function waitForAnimations(page: Page, selector?: string, timeout = 5000): Promise<void> {
    await page.evaluate(
        async ({ selector, timeout }) => {
            const timeoutPromise = new Promise<void>((resolve) => setTimeout(resolve, timeout))
            const root = selector ? document.querySelector(selector) : document.body

            const waitForAll = async () => {
                const animations = root?.getAnimations({ subtree: true }) ?? []
                if (animations.length > 0) {
                    await Promise.race([
                        Promise.all(animations.map((animation) => animation.finished.catch(() => {}))),
                        timeoutPromise,
                    ])
                }
            }

            // Twice: finishing the first round can start the animations it was chaining.
            await waitForAll()
            await waitForAll()
        },
        { selector, timeout },
    )
}

// fullPage captures past the viewport instead of scrolling to it, so a lazy
// element below the fold never starts loading at all.
export async function loadLazyElements(page: Page): Promise<void> {
    await page.evaluate(() =>
        document
            .querySelectorAll<HTMLImageElement | HTMLIFrameElement>('[loading="lazy"]')
            .forEach((element) => (element.loading = 'eager')),
    )
}

async function waitForImagesDecoded(page: Page, timeout = 15000): Promise<void> {
    await page.evaluate(async (timeout) => {
        const images = Array.from(document.querySelectorAll('img'))
        const settle = (image: HTMLImageElement) =>
            new Promise<void>((resolve) => {
                const finish = async () => {
                    await image.decode().catch(() => {})
                    resolve()
                }
                if (image.complete) {
                    void finish()
                    return
                }
                image.addEventListener('load', () => void finish(), { once: true })
                image.addEventListener('error', () => resolve(), { once: true })
            })
        const timeoutPromise = new Promise<void>((resolve) => setTimeout(resolve, timeout))
        await Promise.race([Promise.all(images.map(settle)), timeoutPromise])
        await new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve())))
    }, timeout)
}

/**
 * Finishes the `media="print"` async-CSS trick by hand. A stylesheet loaded as
 * print and swapped to `all` on load is not applied while it is still print, so
 * a screenshot taken mid-swap captures the page unstyled.
 */
async function applyDeferredStylesheets(page: Page): Promise<void> {
    await page.evaluate(
        () =>
            new Promise<void>((resolve) => {
                const twoFrames = () => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))
                const links = Array.from(document.querySelectorAll<HTMLLinkElement>('link[media="print"]'))
                if (links.length === 0) {
                    twoFrames()
                    return
                }

                const settled = links.map(
                    (link) =>
                        new Promise<void>((done) => {
                            if (link.sheet) {
                                link.media = 'all'
                                done()
                                return
                            }
                            link.addEventListener('load', () => done(), { once: true })
                            link.addEventListener('error', () => done(), { once: true })
                            link.media = 'all'
                        }),
                )

                // Resolving on rejection too: a stylesheet that never settles must
                // cost a frame, not the whole screenshot.
                Promise.all(settled).then(twoFrames, resolve)
            }),
    )
}

export function resolveScreenshotTarget(
    target: Page | Locator,
    include?: string,
): { shot: Page | Locator; wholePage: boolean } {
    if (undefined !== include) {
        return { shot: target.locator(include), wholePage: false }
    }

    return { shot: target, wholePage: !('page' in target) }
}

export interface ScreenshotOptions extends PageAssertionsToHaveScreenshotOptions {
    /** Shoot only this element. */
    include?: string
}

export async function takeScreenshot(
    target: Page | Locator,
    name: string,
    options: ScreenshotOptions = {},
): Promise<void> {
    const config = getToolkitConfig()
    const { include, ...screenshotOptions } = options
    const { shot, wholePage } = resolveScreenshotTarget(target, include)
    const page = 'page' in target ? target.page() : target

    await page.addStyleTag({ content: FREEZE_STYLES })

    const hideStyles = buildHideStyles(config.hideBeforeScreenshot ?? [])
    if (hideStyles) {
        await page.addStyleTag({ content: hideStyles })
    }

    await applyDeferredStylesheets(page)
    await page.evaluate(() => document.fonts.ready)
    await waitForAnimations(page, undefined, 3000)
    await loadLazyElements(page)
    await waitForImagesDecoded(page)

    // Playwright creates a missing reference itself and has --update-snapshots
    // for the rest; hand-building the snapshot path got the platform suffix
    // wrong off Linux.
    await expect(shot).toHaveScreenshot(`${name}.png`, {
        animations: 'disabled',
        timeout: 15000,
        threshold: config.screenshot?.threshold ?? 0.2,
        ...(wholePage ? { fullPage: true } : {}),
        ...screenshotOptions,
    })
}
