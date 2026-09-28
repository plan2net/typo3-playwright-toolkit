import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as http from 'http'
import * as os from 'os'
import * as path from 'path'
import { chromium, type Browser } from '@playwright/test'
import { applyToolkitHeaders } from '#src/http/off-site-headers.js'
import { TEST_ID_HEADER } from '#src/contract.js'
import { ensureRunNamespace } from '#src/state/run-namespace.js'
import { writeSitesFile } from '#src/sites/registry.js'
import { configForRun } from '../helpers.js'

const TEST_ID = 'ABCD1234EFGH5678'

let browser: Browser
let root: string
const servers: http.Server[] = []
const received = new Map<string, Array<string | undefined>>()
const origins: Record<string, string> = {}

async function listen(name: string, body: () => string): Promise<string> {
    const server = http.createServer((request, response) => {
        received.set(name, [...(received.get(name) ?? []), request.headers[TEST_ID_HEADER.toLowerCase()] as string | undefined])
        response.writeHead(200, { 'content-type': 'text/html' })
        response.end(body())
    })
    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve))
    servers.push(server)
    const address = server.address()

    return `http://127.0.0.1:${typeof address === 'object' && address ? address.port : 0}`
}

beforeAll(async () => {
    origins.third = await listen('third', () => '<html>third</html>')
    origins.shop = await listen('shop', () => '<html>shop</html>')
    origins.main = await listen('main', () => `<html><img src="${origins.shop}/logo.png"><img src="${origins.third}/pixel.png"></html>`)
    root = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-several-sites-'))
    browser = await chromium.launch()
})

afterAll(async () => {
    await browser?.close()
    await Promise.all(servers.map((server) => new Promise<void>((resolve) => server.close(() => resolve()))))
    fs.rmSync(root, { recursive: true, force: true })
})

// A real browser, because images and navigations take paths a mock cannot show.
describe('a run with two proven sites', () => {
    it('carries the test ID to both sites and not to a third party', async () => {
        const config = { ...configForRun(root, 'aaaaaaaaaaaaaaaa'), testingURL: origins.main }
        ensureRunNamespace(config)
        writeSitesFile(config, { sites: [{ identifier: 'shop', rootPageId: 2, base: `${origins.shop}/`, available: true }] })
        const context = await browser.newContext()
        await applyToolkitHeaders(context, config, TEST_ID)
        const page = await context.newPage()

        await page.goto(origins.main, { waitUntil: 'load' })
        await page.goto(`${origins.shop}/`)
        await context.close()

        expect(received.get('main')).toEqual([TEST_ID])
        expect(received.get('shop')?.length).toBeGreaterThan(1)
        expect(received.get('shop')?.every((value) => TEST_ID === value)).toBe(true)
        expect(received.get('third')?.every((value) => undefined === value)).toBe(true)
    })
})
