import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import * as http from 'http'
import { chromium, type Browser } from '@playwright/test'
import { applyToolkitHeaders } from '#src/http/off-site-headers.js'
import { prepareScenarioContext } from '#src/http/prepare-context.js'
import { toolkitRequest } from '#src/http/toolkit-request.js'
import { setToolkitConfig, type ToolkitConfig } from '#src/config.js'
import { toolkitHeaders } from '#src/contract.js'

const TEST_ID = 'ABCD1234EFGH5678'

let browser: Browser
let server: http.Server
let elsewhere: http.Server
let received: Record<string, string | string[] | undefined>[] = []
let receivedElsewhere: Record<string, string | string[] | undefined>[] = []
let origin: string
let elsewhereOrigin: string

const REDIRECT_PATH = '/redirect-elsewhere'

function originOf(listening: http.Server): string {
    const address = listening.address()

    return `http://127.0.0.1:${typeof address === 'object' && address ? address.port : 0}`
}

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
    elsewhere = http.createServer((request, response) => {
        receivedElsewhere.push(request.headers)
        response.writeHead(200, { 'content-type': 'text/html' })
        response.end('<html><body>elsewhere</body></html>')
    })
    await new Promise<void>((resolve) => elsewhere.listen(0, '127.0.0.1', resolve))
    elsewhereOrigin = originOf(elsewhere)

    server = http.createServer((request, response) => {
        received.push(request.headers)
        if (request.url?.startsWith(REDIRECT_PATH)) {
            response.writeHead(302, { location: `${elsewhereOrigin}/landed` })
            response.end()

            return
        }
        response.writeHead(200, { 'content-type': 'text/html' })
        response.end('<html><body>ok</body></html>')
    })
    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve))
    origin = originOf(server)

    browser = await chromium.launch()
})

afterAll(async () => {
    await browser?.close()
    await new Promise<void>((resolve) => server.close(() => resolve()))
    await new Promise<void>((resolve) => elsewhere.close(() => resolve()))
})

// A real browser, because page.request bypasses context.route and a mock cannot show that.
describe('an origin the toolkit is not configured for', () => {
    it('receives neither toolkit header, from navigation or from page.request', async () => {
        // Configured for a different origin than the server we are about to hit.
        setToolkitConfig(config('https://example-testing.test'))
        const context = await browser.newContext()
        await applyToolkitHeaders(context, config('https://example-testing.test'), TEST_ID)
        const page = await context.newPage()
        received = []

        await page.goto(origin)
        await page.request.get(origin)

        expect(received.length).toBeGreaterThanOrEqual(2)
        for (const headers of received) {
            expect(headers['x-playwright-test-id']).toBeUndefined()
            expect(headers['x-playwright-toolkit-secret']).toBeUndefined()
        }

        await context.close()
    })

    it('still gives the testing origin its test id', async () => {
        setToolkitConfig(config(origin))
        const context = await browser.newContext()
        await applyToolkitHeaders(context, config(origin), TEST_ID)
        const page = await context.newPage()
        received = []

        await page.goto(origin)

        expect(received[0]?.['x-playwright-test-id']).toBe(TEST_ID)

        await context.close()
    })
})

describe('the request client, which routing never reaches', () => {
    it('sends no test id unwrapped, which is why it has to be wrapped', async () => {
        setToolkitConfig(config(origin))
        const context = await browser.newContext()
        await applyToolkitHeaders(context, config(origin), TEST_ID)
        const page = await context.newPage()
        received = []

        await page.request.get(origin)

        expect(received[0]?.['x-playwright-test-id']).toBeUndefined()

        await context.close()
    })

    it('reaches the per-test database once wrapped', async () => {
        setToolkitConfig(config(origin))
        const context = await browser.newContext()
        const page = await context.newPage()
        received = []

        await toolkitRequest(page.request, config(origin), TEST_ID).get(origin)

        expect(received[0]?.['x-playwright-test-id']).toBe(TEST_ID)

        await context.close()
    })

    it('still tells a third party nothing', async () => {
        setToolkitConfig(config('https://example-testing.test'))
        const context = await browser.newContext()
        const page = await context.newPage()
        received = []

        await toolkitRequest(page.request, config('https://example-testing.test'), TEST_ID).get(origin)

        expect(received[0]?.['x-playwright-test-id']).toBeUndefined()

        await context.close()
    })
})

describe('a redirect off the testing origin', () => {
    // What the session POST and record/edit do. Following the redirect would hand
    // the secret to whatever the site under test named.
    it('carries no secret to the origin it lands on', async () => {
        setToolkitConfig(config(origin))
        const context = await browser.newContext()
        const page = await context.newPage()
        receivedElsewhere = []

        await page.request.get(`${origin}${REDIRECT_PATH}`, {
            headers: toolkitHeaders(config(origin), TEST_ID),
            maxRedirects: 0,
        })

        expect(receivedElsewhere).toHaveLength(0)

        await context.close()
    })

    // Playwright fires `request` for the hop after a redirect but never the route
    // handler, so no header can be taken off it. If this ever fails, that is gone
    // and the test ID can be stripped after all.
    it('cannot keep the test id off it, the hop being unroutable', async () => {
        setToolkitConfig(config(origin))
        const context = await browser.newContext()
        await applyToolkitHeaders(context, config(origin), TEST_ID)
        const page = await context.newPage()
        receivedElsewhere = []

        await page.goto(`${origin}${REDIRECT_PATH}`)

        expect(receivedElsewhere[0]?.['x-playwright-test-id']).toBe(TEST_ID)

        await context.close()
    })
})

describe('a consumer route added through prepareContext', () => {
    it('cannot take the test id off a request it continues', async () => {
        setToolkitConfig(config(origin))
        const context = await browser.newContext()
        await prepareScenarioContext(
            context,
            {
                ...config(origin),
                prepareContext: async (given) => {
                    await given.route('**/*', (route) => route.continue())
                },
            },
            TEST_ID,
        )
        const page = await context.newPage()
        received = []

        await page.goto(origin)

        expect(received[0]?.['x-playwright-test-id']).toBe(TEST_ID)

        await context.close()
    })

    it('still gets to fulfil a request itself', async () => {
        setToolkitConfig(config(origin))
        const context = await browser.newContext()
        await prepareScenarioContext(
            context,
            {
                ...config(origin),
                prepareContext: async (given) => {
                    await given.route('**/stub.js', (route) =>
                        route.fulfill({ body: 'stubbed', contentType: 'text/javascript' }),
                    )
                },
            },
            TEST_ID,
        )
        const page = await context.newPage()
        await page.goto(origin)
        received = []

        const body = await page.evaluate(async () => (await fetch('/stub.js')).text())

        expect(body).toBe('stubbed')

        await context.close()
    })
})
