import { expect, Locator, Page } from '@playwright/test'
import { getToolkitConfig } from '../config.js'

/** Playwright only named this type in 1.56. */
export interface ScreenshotComparisonOptions {
    animations?: 'disabled' | 'allow'
    caret?: 'hide' | 'initial'
    clip?: { x: number; y: number; width: number; height: number }
    fullPage?: boolean
    mask?: Locator[]
    maskColor?: string
    maxDiffPixelRatio?: number
    maxDiffPixels?: number
    omitBackground?: boolean
    scale?: 'css' | 'device'
    signal?: AbortSignal
    stylePath?: string | string[]
    threshold?: number
    timeout?: number
}

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
 * expectScreenshot already waits; call this when you interact and then assert
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

// A capture reaching past the viewport collapses it to 1x1 first, and a width-based
// `media` on a `<source>` flips while it is collapsed, dropping the decoded image.
export async function freezeResponsiveImages(page: Page): Promise<void> {
    await page.evaluate(() => {
        document.querySelectorAll('img').forEach((image) => {
            const resolved = image.currentSrc
            if (!resolved) {
                return
            }

            image.dataset.toolkitFrozen = JSON.stringify({
                src: image.getAttribute('src'),
                srcset: image.getAttribute('srcset'),
                sizes: image.getAttribute('sizes'),
                width: image.getAttribute('width'),
                height: image.getAttribute('height'),
            })
            const sources = Array.from(image.closest('picture')?.querySelectorAll('source') ?? [])
            // width and height on the chosen source lay the img out, and disabling it
            // would drop them.
            const chosen = sources.find((source) => !source.media || matchMedia(source.media).matches)
            for (const dimension of ['width', 'height']) {
                const value = chosen?.getAttribute(dimension)
                if (null != value) {
                    image.setAttribute(dimension, value)
                }
            }

            sources.forEach((source) => {
                source.dataset.toolkitFrozen = JSON.stringify({ media: source.getAttribute('media') })
                // Never matches, so selection falls through to the img below.
                source.media = 'not all'
            })

            image.removeAttribute('srcset')
            image.removeAttribute('sizes')
            if (image.src !== resolved) {
                image.src = resolved
            }
        })
    })
}

// Settle first, or a loading image has no currentSrc to pin it to; settle again,
// because pinning a <picture> starts a load of its own.
export async function prepareImagesForCapture(page: Page): Promise<string[]> {
    const stalled = await waitForImagesDecoded(page)
    await freezeResponsiveImages(page)
    await waitForImagesDecoded(page)

    return stalled
}

export async function restoreResponsiveImages(page: Page): Promise<void> {
    await page.evaluate(() => {
        const put = (element: Element, name: string, value: string | null) =>
            null === value ? element.removeAttribute(name) : element.setAttribute(name, value)

        document.querySelectorAll<HTMLImageElement>('img[data-toolkit-frozen]').forEach((image) => {
            image
                .closest('picture')
                ?.querySelectorAll<HTMLSourceElement>('source[data-toolkit-frozen]')
                .forEach((source) => {
                    const { media } = JSON.parse(source.dataset.toolkitFrozen ?? '{}') as {
                        media: string | null
                    }
                    put(source, 'media', media)
                    delete source.dataset.toolkitFrozen
                })

            const frozen = JSON.parse(image.dataset.toolkitFrozen ?? '{}') as Record<string, string | null>
            Object.entries(frozen).forEach(([name, value]) => put(image, name, value))
            delete image.dataset.toolkitFrozen
        })
    })
}

const DECODE_TIMEOUT = 15000

/** @returns the sources of the images that never decoded, empty when all did */
export async function waitForImagesDecoded(page: Page, timeout = DECODE_TIMEOUT): Promise<string[]> {
    const deadline = Date.now() + timeout

    const stalled = await settleImages(page, timeout)
    const left = deadline - Date.now()
    // Out of budget, so a second round would settle nothing and report every image.
    if (left <= 0) {
        return stalled
    }

    // A load that starts during a round is not waited for by it, which is how
    // applyDeferredStylesheets re-selects a source after the image read as complete.
    return settleImages(page, left)
}

function settleImages(page: Page, timeout: number): Promise<string[]> {
    return page.evaluate(async (timeout) => {
        const images = Array.from(document.querySelectorAll('img'))
        const stalled = new Set(images)
        const settle = (image: HTMLImageElement) =>
            new Promise<void>((resolve) => {
                const finish = async () => {
                    await image.decode().catch(() => {})
                    stalled.delete(image)
                    resolve()
                }
                if (image.complete) {
                    void finish()
                    return
                }
                image.addEventListener('load', () => void finish(), { once: true })
                image.addEventListener(
                    'error',
                    () => {
                        stalled.delete(image)
                        resolve()
                    },
                    { once: true },
                )
            })
        const timeoutPromise = new Promise<void>((resolve) => setTimeout(resolve, timeout))
        await Promise.race([Promise.all(images.map(settle)), timeoutPromise])
        await new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve())))

        // The snapshot above predates anything that started loading since.
        document.querySelectorAll('img').forEach((image) => {
            if (!image.complete) {
                stalled.add(image)
            }
        })

        return Array.from(stalled, (image) => image.currentSrc || image.src)
    }, timeout)
}

/** An undecoded image is blank, and a first run writes that blank as the baseline. */
export function warnAboutUndecodedImages(stalled: string[], timeout: number): void {
    console.warn(
        `[typo3-playwright-toolkit] ${stalled.length} ${1 === stalled.length ? 'image' : 'images'} ` +
            `did not decode within ${timeout}ms and will be blank in the screenshot:\n  ` +
            stalled.join('\n  '),
    )
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

export interface ScreenshotOptions extends ScreenshotComparisonOptions {
    /** Shoot only this element. */
    include?: string
    /** Replaces `hideBeforeScreenshot` for this shot; `[]` hides nothing. */
    hide?: string[]
}

export function hiddenSelectors(configured: string[] | undefined, perCall: string[] | undefined): string[] {
    return perCall ?? configured ?? []
}

export async function expectScreenshot(
    target: Page | Locator,
    name: string,
    options: ScreenshotOptions = {},
): Promise<void> {
    const config = getToolkitConfig()
    const { include, hide, ...screenshotOptions } = options
    const { shot, wholePage } = resolveScreenshotTarget(target, include)
    const page = 'page' in target ? target.page() : target

    await page.addStyleTag({ content: FREEZE_STYLES })

    const hideStyles = buildHideStyles(hiddenSelectors(config.hideBeforeScreenshot, hide))
    if (hideStyles) {
        await page.addStyleTag({ content: hideStyles })
    }

    await applyDeferredStylesheets(page)
    await page.evaluate(() => document.fonts.ready)
    await waitForAnimations(page, undefined, 3000)
    await loadLazyElements(page)
    const stalled = await prepareImagesForCapture(page)
    if (stalled.length > 0) {
        warnAboutUndecodedImages(stalled, DECODE_TIMEOUT)
    }

    try {
        // Playwright creates a missing reference itself and has --update-snapshots
        // for the rest; hand-building the snapshot path got the platform suffix
        // wrong off Linux.
        await expect(shot).toHaveScreenshot(`${name}.png`, comparisonOptions(wholePage, screenshotOptions))
    } finally {
        await restoreResponsiveImages(page)
    }
}

export function comparisonOptions(
    wholePage: boolean,
    perCall: ScreenshotComparisonOptions,
): ScreenshotComparisonOptions {
    return {
        animations: 'disabled',
        timeout: 15000,
        ...(wholePage ? { fullPage: true } : {}),
        ...perCall,
    }
}
