import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest'
import { chromium, type Browser, type Page } from '@playwright/test'
import { captureWouldBeTruncated, SCROLL_RESET_STYLES } from '#src/checks/screenshot.js'

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

        await page.addStyleTag({ content: SCROLL_RESET_STYLES })

        expect(truncated).toBe(0)
        expect(await inkAtTheBottom()).toBeGreaterThan(0)
    })

    it('captures it under a root scroll padding too', async () => {
        await page.addStyleTag({
            content: `#target { scroll-margin-top: 0 } :root { scroll-padding-top: ${SCROLL_MARGIN}px }`,
        })

        const truncated = await inkAtTheBottom()

        await page.addStyleTag({ content: SCROLL_RESET_STYLES })

        expect(truncated).toBe(0)
        expect(await inkAtTheBottom()).toBeGreaterThan(0)
    })

    it('sees that the scroll margin would truncate this capture', async () => {
        expect(await captureWouldBeTruncated(page.locator('#target'))).toBe(true)
    })

    // Leaving it alone is what keeps a sticky header clear of it, as its margin asks.
    it('leaves an element that lands fully inside the viewport alone', async () => {
        await page.setContent(PAGE.replace(`height:${ELEMENT_HEIGHT}px`, 'height:400px'))

        expect(await captureWouldBeTruncated(page.locator('#target'))).toBe(false)
    })
})
