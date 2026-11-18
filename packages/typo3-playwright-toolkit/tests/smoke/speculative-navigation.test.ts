import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import * as http from 'http'
import { chromium, type Browser } from '@playwright/test'
import { prepareScenarioContext } from '#src/http/prepare-context.js'
import { setToolkitConfig, type ToolkitConfig } from '#src/config.js'

const TEST_ID = 'ABCD1234EFGH5678'
const PREPARED_PATH = '/prepared'

let browser: Browser
let server: http.Server
let elsewhere: http.Server
let received: { url: string; headers: http.IncomingHttpHeaders }[] = []
let receivedElsewhere: http.IncomingHttpHeaders[] = []
let origin: string
let elsewhereOrigin: string

function portOf(listening: http.Server): number {
    const address = listening.address()

    return typeof address === 'object' && address ? address.port : 0
}

function config(testingURL: string): ToolkitConfig {
    return {
        testingURL,
        contentTypes: {},
        paths: {
            consumerRoot: '/srv/project',
            stateDir: '/srv/project/.test-state',
        },
    }
}

const SPECULATING_PAGE = `<html><body>
<a href="${PREPARED_PATH}">Prepared</a>
<script type="speculationrules">
{"prerender": [{"source": "list", "urls": ["${PREPARED_PATH}"], "eagerness": "moderate"}]}
</script>
</body></html>`

beforeAll(async () => {
    server = http.createServer((request, response) => {
        received.push({ url: request.url ?? '', headers: request.headers })
        response.writeHead(200, { 'content-type': 'text/html' })
        response.end(request.url === PREPARED_PATH ? '<html><body>prepared</body></html>' : SPECULATING_PAGE)
    })
    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve))
    origin = `http://127.0.0.1:${portOf(server)}`

    elsewhere = http.createServer((request, response) => {
        receivedElsewhere.push(request.headers)
        response.writeHead(200, { 'content-type': 'text/html' })
        response.end('<html><body>elsewhere</body></html>')
    })
    await new Promise<void>((resolve) => elsewhere.listen(0, 'localhost', resolve))
    elsewhereOrigin = `http://localhost:${portOf(elsewhere)}`

    browser = await chromium.launch()
})

afterAll(async () => {
    await browser?.close()
    await new Promise<void>((resolve) => server.close(() => resolve()))
    await new Promise<void>((resolve) => elsewhere.close(() => resolve()))
})

// A real browser, because only Chromium shows which requests escape context.route.
describe('a page the browser fetches ahead through speculation rules', () => {
    it('carries the test id, although routing never sees the request', async () => {
        setToolkitConfig(config(origin))
        const context = await browser.newContext()
        await prepareScenarioContext(context, config(origin), TEST_ID)
        const page = await context.newPage()
        await page.goto(origin)
        received = []

        await page.click(`a[href="${PREPARED_PATH}"]`)
        await expect.poll(() => page.textContent('body')).toBe('prepared')

        const prepared = received.filter((request) => request.url === PREPARED_PATH)
        expect(prepared).toHaveLength(1)
        expect(prepared[0]?.headers['sec-purpose']).toContain('prefetch')
        expect(prepared[0]?.headers['x-playwright-test-id']).toBeUndefined()
        expect(prepared[0]?.headers.cookie).toContain(`playwright_test_id=${TEST_ID}`)

        await context.close()
    })

    it('leaves another host without the test id cookie', async () => {
        setToolkitConfig(config(origin))
        const context = await browser.newContext()
        await prepareScenarioContext(context, config(origin), TEST_ID)
        const page = await context.newPage()
        receivedElsewhere = []

        await page.goto(elsewhereOrigin)

        expect(receivedElsewhere).toHaveLength(1)
        expect(receivedElsewhere[0]?.cookie).toBeUndefined()

        await context.close()
    })
})
