import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { chromium, type Browser, type Page, type Route } from '@playwright/test'
import { waitForImagesDecoded } from '#src/checks/screenshot.js'

let browser: Browser
let page: Page

const GIF = Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64')

const serve = (route: Route) => route.fulfill({ status: 200, contentType: 'image/gif', body: GIF })

const after = async (ms: number, route: Route) => {
    await new Promise((resolve) => setTimeout(resolve, ms))

    return serve(route)
}

beforeAll(async () => {
    browser = await chromium.launch()
    page = await browser.newPage({ viewport: { width: 800, height: 600 } })
})

afterAll(async () => {
    await browser?.close()
})

describe('waitForImagesDecoded', () => {
    it('names an image that never decoded instead of going quiet', async () => {
        // Never fulfilled, so the decode loses the race.
        await page.route('https://example.test/stuck.gif', () => {})
        await page.route('https://example.test/fine.gif', serve)
        // An unfinished request holds back the load event.
        await page.setContent(
            `<img src="https://example.test/fine.gif"><img src="https://example.test/stuck.gif">`,
            { waitUntil: 'domcontentloaded' },
        )

        const stalled = await waitForImagesDecoded(page, 500)

        expect(stalled).toEqual(['https://example.test/stuck.gif'])
    }, 30000)

    // The round that times out took its list before this one started loading.
    it('names both images when one starts loading during a round that times out', async () => {
        await page.route('https://example.test/first.gif', () => {})
        await page.route('https://example.test/second.gif', () => {})
        await page.setContent(`<img id="first" src="https://example.test/first.gif"><img id="second">`, {
            waitUntil: 'domcontentloaded',
        })
        await page.evaluate(() =>
            setTimeout(() => {
                document.querySelector<HTMLImageElement>('#second')!.src = 'https://example.test/second.gif'
            }, 100),
        )

        const stalled = await waitForImagesDecoded(page, 500)

        expect(stalled.slice().sort()).toEqual([
            'https://example.test/first.gif',
            'https://example.test/second.gif',
        ])
    }, 30000)

    // Stands in for the deferred stylesheet re-selecting a source.
    it('waits for a load that starts while it is already waiting', async () => {
        await page.route('https://example.test/slow.gif', (route) => after(300, route))
        await page.route('https://example.test/late.gif', (route) => after(200, route))
        await page.setContent(`<img id="slow" src="https://example.test/slow.gif"><img id="late">`, {
            waitUntil: 'domcontentloaded',
        })
        await page.evaluate(() =>
            document.getElementById('slow')?.addEventListener('load', () => {
                document.querySelector<HTMLImageElement>('#late')!.src = 'https://example.test/late.gif'
            }),
        )

        await waitForImagesDecoded(page, 5000)

        const painted = await page.evaluate(
            () => document.querySelector<HTMLImageElement>('#late')?.naturalWidth ?? 0,
        )
        expect(painted).toBeGreaterThan(0)
    }, 30000)
})
