import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import { chromium, type Browser, type Page } from '@playwright/test'
import { waitForAnimations } from '#src/checks/screenshot.js'

let browser: Browser
let page: Page

const ANIMATED = `
<html><body>
  <div id="outside" style="width:10px"></div>
  <div id="scope"><div id="inside" style="width:10px"></div></div>
  <style>
    @keyframes grow { from { width: 10px } to { width: 200px } }
    .run { animation: grow 300ms linear forwards }
  </style>
</body></html>`

beforeAll(async () => {
    browser = await chromium.launch()
    page = await browser.newPage()
})

afterAll(async () => {
    await browser?.close()
})

describe('waitForAnimations', () => {
    it('returns only once a running animation has finished', async () => {
        await page.setContent(ANIMATED)
        await page.evaluate(() => document.getElementById('inside')?.classList.add('run'))

        await waitForAnimations(page)

        const width = await page.evaluate(() => document.getElementById('inside')?.clientWidth)
        expect(width).toBe(200)
    })

    it('waits for animations inside the selector it is given', async () => {
        await page.setContent(ANIMATED)
        await page.evaluate(() => document.getElementById('inside')?.classList.add('run'))

        await waitForAnimations(page, '#scope')

        expect(await page.evaluate(() => document.getElementById('inside')?.clientWidth)).toBe(200)
    })

    // A selector that animates nothing must not block on the rest of the page.
    it('returns promptly when the selector holds no animation', async () => {
        await page.setContent(ANIMATED)
        await page.evaluate(() => document.getElementById('inside')?.classList.add('run'))

        await waitForAnimations(page, '#outside', 5000)

        expect(await page.evaluate(() => document.getElementById('inside')?.clientWidth)).toBeLessThan(200)
    })

    it('gives up after the timeout rather than hanging', async () => {
        await page.setContent(
            '<html><body><div id="forever"></div><style>@keyframes spin{from{opacity:1}to{opacity:0}}' +
                '#forever{animation:spin 60s linear infinite}</style></body></html>',
        )

        const started = Date.now()
        await waitForAnimations(page, undefined, 300)

        expect(Date.now() - started).toBeLessThan(5000)
    })
})
