import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { chromium, type Browser, type Page } from '@playwright/test'
import { loadLazyElements } from '#src/checks/screenshot.js'

let browser: Browser
let page: Page

const GIF = Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64')

const BELOW_THE_FOLD = `<html><body>
  <div style="height:30000px"></div>
  <img loading="lazy" width="100" height="100" src="https://example.test/pixel.gif">
</body></html>`

const imageLoaded = () => document.querySelector('img')?.complete === true

beforeAll(async () => {
    browser = await chromium.launch()
    page = await browser.newPage({ viewport: { width: 800, height: 600 } })
    await page.route('https://example.test/**', (route) =>
        route.fulfill({ status: 200, contentType: 'image/gif', body: GIF }),
    )
})

afterAll(async () => {
    await browser?.close()
})

describe('loadLazyElements', () => {
    // Without it waitForImagesDecoded burns its whole timeout and shoots the placeholder.
    it('leaves a below-the-fold lazy image unloaded when it is not called', async () => {
        await page.setContent(BELOW_THE_FOLD)

        await expect(page.waitForFunction(imageLoaded, null, { timeout: 1000 })).rejects.toThrow()
    })

    it('loads a below-the-fold lazy image', async () => {
        await page.setContent(BELOW_THE_FOLD)

        await loadLazyElements(page)

        await page.waitForFunction(imageLoaded, null, { timeout: 5000 })
    })
})
