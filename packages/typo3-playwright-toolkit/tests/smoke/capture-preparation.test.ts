import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { chromium, type Browser, type Page } from '@playwright/test'
import { prepareForCapture } from '#src/checks/screenshot.js'

let browser: Browser
let page: Page

beforeAll(async () => {
    browser = await chromium.launch()
    page = await browser.newPage({ viewport: { width: 800, height: 600 } })
    await page.route('https://example.test/print.css', (route) =>
        route.fulfill({ status: 200, contentType: 'text/css', body: 'nav { display: none; }' }),
    )
})

afterAll(async () => {
    await browser?.close()
})

describe('prepareForCapture', () => {
    it('leaves a print stylesheet to print', async () => {
        await page.setContent(
            '<html><head><link rel="stylesheet" media="print" href="https://example.test/print.css"></head>' +
                '<body><nav>Navigation</nav></body></html>',
        )

        await prepareForCapture(page)

        expect(await page.locator('nav').isVisible()).toBe(true)
    })
})
