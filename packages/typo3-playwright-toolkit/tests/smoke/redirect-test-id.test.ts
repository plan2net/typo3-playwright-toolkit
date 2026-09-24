import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import * as http from 'http'
import { firefox, type Browser } from '@playwright/test'
import { applyToolkitHeaders } from '#src/http/off-site-headers.js'
import { setToolkitConfig, type ToolkitConfig } from '#src/config.js'

const TEST_ID = 'ABCD1234EFGH5678'

let browser: Browser
let server: http.Server
let received: { url?: string; testId?: string | string[] }[] = []
let origin: string

function config(testingURL: string): ToolkitConfig {
    return {
        testingURL,
        contentTypes: {},
        paths: {
            consumerRoot: '/srv/project',
            stateDir: '/srv/project/.test-state',
            sessionDir: '/srv/project/var/session',
        },
    }
}

beforeAll(async () => {
    server = http.createServer((request, response) => {
        received.push({ url: request.url, testId: request.headers['x-playwright-test-id'] })
        if ('/here' === request.url) {
            response.writeHead(302, { location: '/landed' })
            response.end()

            return
        }
        response.writeHead(200, { 'content-type': 'text/html' })
        response.end('<html><body>ok</body></html>')
    })
    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve))
    const address = server.address()
    origin = `http://127.0.0.1:${typeof address === 'object' && address ? address.port : 0}`

    browser = await firefox.launch()
})

afterAll(async () => {
    await browser?.close()
    await new Promise<void>((resolve) => server.close(() => resolve()))
})

// Firefox, because below Playwright 1.47 it drops the header after a redirect,
// without an error. Chromium and WebKit do not.
describe('a redirect in Firefox, with the toolkit routing the context', () => {
    it('keeps the test id on the page a same-origin redirect lands on', async () => {
        setToolkitConfig(config(origin))
        const context = await browser.newContext()
        await applyToolkitHeaders(context, config(origin), TEST_ID)
        const page = await context.newPage()
        received = []

        await page.goto(`${origin}/here`)

        expect(received.find((request) => '/landed' === request.url)?.testId).toBe(TEST_ID)

        await context.close()
    })
})
