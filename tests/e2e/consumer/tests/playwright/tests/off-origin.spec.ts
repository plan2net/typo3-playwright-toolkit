import { defineScenario, expect } from '@plan2net/typo3-playwright-toolkit'

// The project's ordinary hostname. Only the -testing one is bound to the Testing
// context, and nothing else in the suite ever visits this one.
const ORDINARY_URL = 'https://t3pw-e2e.ddev.site'

export const test = defineScenario()

test('leaves the ordinary hostname outside the test run', async ({ page }) => {
    const sent: Record<string, string>[] = []
    page.on('request', (request) => sent.push(request.headers()))

    const response = await page.goto(`${ORDINARY_URL}/typo3/test-api/health`)

    // Outside the Testing context the middleware passes the request through and
    // core answers it — a redirect to the backend login, never the health JSON. If
    // the nginx map put both hostnames in Testing this would answer like the test
    // API, and the other spec would still pass, so this is what proves the binding
    // is per host.
    expect(await response?.text()).not.toContain('"success"')

    expect(sent.some((headers) => 'x-playwright-test-id' in headers)).toBe(false)
})
