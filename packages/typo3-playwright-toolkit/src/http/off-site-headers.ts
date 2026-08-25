import type { BrowserContext } from '@playwright/test'
import { SECRET_HEADER } from './api-secret.js'
import { TEST_ID_HEADER, browserHeaders } from '../contract.js'
import type { ToolkitConfig } from '../config.js'

const TOOLKIT_HEADERS = [TEST_ID_HEADER.toLowerCase(), SECRET_HEADER.toLowerCase()]

/**
 * Adds the test ID for the site under test, and takes both toolkit headers off
 * anything else. Routing rather than extraHTTPHeaders: a context-wide header also
 * rides on page.request, which context.route cannot reach.
 *
 * Register this last. Playwright runs the newest matching handler first, and
 * `fallback` then passes the request down to the ones a consumer added earlier —
 * `continue` would end the chain and silently disable them.
 */
export async function applyToolkitHeaders(
    context: BrowserContext,
    config: ToolkitConfig,
    testId: string,
): Promise<void> {
    const site = new URL(config.testingURL).origin

    await context.route(
        () => true,
        async (route) => {
            const headers = { ...route.request().headers() }

            if (new URL(route.request().url()).origin === site) {
                await route.fallback({ headers: { ...headers, ...browserHeaders(testId) } })

                return
            }

            for (const name of TOOLKIT_HEADERS) {
                delete headers[name]
            }

            await route.fallback({ headers })
        },
    )
}
