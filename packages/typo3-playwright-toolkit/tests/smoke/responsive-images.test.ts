import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest'
import { chromium, type Browser, type Page } from '@playwright/test'
import {
    freezeResponsiveImages,
    prepareImagesForCapture,
    restoreResponsiveImages,
} from '#src/checks/screenshot.js'

let browser: Browser
let page: Page

const GIF = Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64')

const ART_DIRECTED = `<html><body style="margin:0">
  <picture>
    <source media="(max-width: 768px)" srcset="https://example.test/mobile.gif">
    <source media="(min-width: 769px)" srcset="https://example.test/desktop.gif">
    <img width="200" height="200" src="https://example.test/fallback.gif">
  </picture>
</body></html>`

const SOURCE_WITH_ITS_OWN_BOX = `<html><body style="margin:0">
  <picture>
    <source media="(min-width: 769px)" width="400" height="200"
            srcset="https://example.test/desktop.gif">
    <img width="100" height="100" src="https://example.test/fallback.gif">
  </picture>
</body></html>`

const selected = () => page.evaluate(() => document.querySelector('img')?.currentSrc)

const box = () => page.evaluate(() => document.querySelector('img')?.offsetWidth)

beforeAll(async () => {
    browser = await chromium.launch()
    page = await browser.newPage({ viewport: { width: 1920, height: 1080 } })
    await page.route('https://example.test/**', (route) =>
        route.fulfill({ status: 200, contentType: 'image/gif', body: GIF }),
    )
})

afterAll(async () => {
    await browser?.close()
})

beforeEach(async () => {
    await page.setViewportSize({ width: 1920, height: 1080 })
    await page.setContent(ART_DIRECTED)
    await page.waitForFunction(() => document.querySelector('img')?.complete === true)
    expect(await selected()).toBe('https://example.test/desktop.gif')
})

describe('freezeResponsiveImages', () => {
    // Narrowing stands in for the capture's collapse to 1x1: both flip a width query.
    it('re-selects within that window when nothing was frozen', async () => {
        await page.setViewportSize({ width: 400, height: 800 })
        await page.waitForTimeout(300)

        expect(await selected()).toBe('https://example.test/mobile.gif')
    }, 30000)

    it('keeps the file a picture resolved to when the viewport changes under it', async () => {
        await freezeResponsiveImages(page)

        await page.setViewportSize({ width: 400, height: 800 })
        // Selection re-runs off the main flow, so an immediate read proves nothing.
        await page.waitForTimeout(300)

        expect(await selected()).toBe('https://example.test/desktop.gif')
    }, 30000)

    // Without it a test that shoots, narrows and shoots again gets the desktop file twice.
    it('leaves the picture able to re-select once the shot is taken', async () => {
        await freezeResponsiveImages(page)

        await restoreResponsiveImages(page)
        await page.setViewportSize({ width: 400, height: 800 })

        await page.waitForFunction(() =>
            document.querySelector('img')?.currentSrc.endsWith('/mobile.gif'),
        )
    }, 30000)

    it('pins an image that had not finished loading yet', async () => {
        await page.route('https://example.test/slow-desktop.gif', async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 300))

            return route.fulfill({ status: 200, contentType: 'image/gif', body: GIF })
        })
        await page.setContent(
            ART_DIRECTED.replace('/desktop.gif', '/slow-desktop.gif').replace(
                '/mobile.gif',
                '/slow-mobile.gif',
            ),
            { waitUntil: 'domcontentloaded' },
        )
        expect(await selected()).toBe('')

        await prepareImagesForCapture(page)
        await page.setViewportSize({ width: 400, height: 800 })
        await page.waitForTimeout(300)

        expect(await selected()).toBe('https://example.test/slow-desktop.gif')
    }, 30000)

    it('keeps the box the selected source gave the image', async () => {
        await page.setContent(SOURCE_WITH_ITS_OWN_BOX)
        await page.waitForFunction(() => document.querySelector('img')?.complete === true)
        expect(await box()).toBe(400)

        await freezeResponsiveImages(page)

        expect(await box()).toBe(400)
    }, 30000)

    // The copied box has to come off, or it lays the image out once the source
    // stops matching.
    it('gives the image its own box back afterwards', async () => {
        await page.setContent(SOURCE_WITH_ITS_OWN_BOX)
        await page.waitForFunction(() => document.querySelector('img')?.complete === true)
        await freezeResponsiveImages(page)
        await restoreResponsiveImages(page)

        await page.setViewportSize({ width: 400, height: 800 })
        await page.waitForTimeout(300)

        expect(await box()).toBe(100)
    }, 30000)
})
