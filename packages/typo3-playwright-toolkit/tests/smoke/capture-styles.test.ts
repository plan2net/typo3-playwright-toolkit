import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest'
import { chromium, type Browser, type Page } from '@playwright/test'
import {
    captureWouldBeTruncated,
    clearScrollResetMarks,
    markForScrollReset,
    SCROLL_RESET_STYLES,
    waitForScrollToSettle,
} from '#src/checks/screenshot.js'

let browser: Browser
let page: Page

const VIEWPORT = { width: 393, height: 727 }
const ELEMENT_HEIGHT = 721
const SCROLL_MARGIN = 120

// The last 60px are painted, so a truncated capture is the one that is white there.
const PAGE = `<html><body style="margin:0">
  <div style="height:400px"></div>
  <div id="target" style="height:${ELEMENT_HEIGHT}px;width:345px;background:#fff;
       scroll-margin-top:${SCROLL_MARGIN}px">
    <div style="height:${ELEMENT_HEIGHT - 60}px"></div>
    <div style="height:60px;background:#000"></div>
  </div>
  <div style="height:1200px"></div>
</body></html>`

async function inkAtTheBottom(): Promise<number> {
    const element = page.locator('#target')
    await element.scrollIntoViewIfNeeded()
    const shot = await element.screenshot({ scale: 'css' })

    return page.evaluate(async (source) => {
        const image = new Image()
        image.src = source
        await image.decode()
        const canvas = document.createElement('canvas')
        canvas.width = image.width
        canvas.height = image.height
        const context = canvas.getContext('2d')
        if (!context) {
            return -1
        }
        context.drawImage(image, 0, 0)
        const band = Math.ceil(image.height * 0.05)
        const { data } = context.getImageData(0, image.height - band, image.width, band)
        let dark = 0
        for (let index = 0; index < data.length; index += 4) {
            if (data[index] + data[index + 1] + data[index + 2] < 720) {
                dark++
            }
        }

        return dark
    }, `data:image/png;base64,${shot.toString('base64')}`)
}

beforeAll(async () => {
    browser = await chromium.launch()
})

afterAll(async () => {
    await browser.close()
})

beforeEach(async () => {
    page = await browser.newPage({ viewport: VIEWPORT })
    await page.setContent(PAGE)
})

describe('capture styles', () => {
    it('captures the bottom of an element its scroll margin pushes past the viewport', async () => {
        const truncated = await inkAtTheBottom()

        await markForScrollReset(page.locator('#target'))
        await page.addStyleTag({ content: SCROLL_RESET_STYLES })

        expect(truncated).toBe(0)
        expect(await inkAtTheBottom()).toBeGreaterThan(0)
    })

    it('captures it under a root scroll padding too', async () => {
        await page.addStyleTag({
            content: `#target { scroll-margin-top: 0 } :root { scroll-padding-top: ${SCROLL_MARGIN}px }`,
        })

        const truncated = await inkAtTheBottom()

        await markForScrollReset(page.locator('#target'))
        await page.addStyleTag({ content: SCROLL_RESET_STYLES })

        expect(truncated).toBe(0)
        expect(await inkAtTheBottom()).toBeGreaterThan(0)
    })

    it('sees that the scroll margin would truncate this capture', async () => {
        expect(await captureWouldBeTruncated(page.locator('#target'))).toBe(true)
    })

    it('returns only once scrolling has stopped', async () => {
        await page.setContent('<body style="margin:0"><div style="height:5000px"></div></body>')
        await page.evaluate(() => {
            let top = 0
            const step = () => {
                top += 50
                window.scrollTo(0, top)
                if (top < 1500) {
                    requestAnimationFrame(step)
                }
            }
            requestAnimationFrame(step)
        })

        await waitForScrollToSettle(page)

        expect(await page.evaluate(() => window.scrollY)).toBe(1500)
    })

    // A slider inside the shot aligns its slides with scroll-padding of its own, and
    // the repair is about the page's offset, not that one.
    it('leaves a scroll container inside the element alone', async () => {
        await page.setContent(`<body style="margin:0"><div id="target" style="scroll-margin-top:120px">
            <div id="carousel" style="overflow-x:auto;scroll-padding-left:40px;width:200px">
                <div style="width:900px;height:50px"></div>
            </div></div></body>`)

        await markForScrollReset(page.locator('#target'))
        await page.addStyleTag({ content: SCROLL_RESET_STYLES })

        expect(
            await page.evaluate(
                () => getComputedStyle(document.querySelector('#carousel') as Element).scrollPaddingLeft,
            ),
        ).toBe('40px')
    })

    // Playwright waits for the element's own box to be stable, which says nothing
    // about a slider still scrolling inside it.
    it('waits for a scroll inside the element as well', async () => {
        await page.setContent(`<body style="margin:0"><div id="target" style="height:400px;width:345px">
            <div id="carousel" style="overflow-x:auto;width:200px"><div style="width:2000px;height:50px"></div></div>
            </div></body>`)
        await page.evaluate(() => {
            const carousel = document.querySelector('#carousel') as Element
            let left = 0
            const step = (): void => {
                left += 20
                carousel.scrollLeft = left
                if (left < 400) {
                    requestAnimationFrame(step)
                }
            }
            requestAnimationFrame(step)
        })

        await captureWouldBeTruncated(page.locator('#target'))

        expect(await page.evaluate(() => (document.querySelector('#carousel') as Element).scrollLeft)).toBe(400)
    })

    it('takes its marks off the page again', async () => {
        await markForScrollReset(page.locator('#target'))

        await clearScrollResetMarks(page)

        expect(
            await page.evaluate(() => document.querySelectorAll('[data-toolkit-capture-target], [data-toolkit-capture-scroller]').length),
        ).toBe(0)
    })

    // Leaving it alone is what keeps a sticky header clear of it, as its margin asks.
    it('leaves an element that lands fully inside the viewport alone', async () => {
        await page.setContent(PAGE.replace(`height:${ELEMENT_HEIGHT}px`, 'height:400px'))

        expect(await captureWouldBeTruncated(page.locator('#target'))).toBe(false)
    })
})
