import type { BrowserContext } from '@playwright/test'
import { SECRET_HEADER } from './api-secret.js'
import { TEST_ID_HEADER, browserHeaders } from '../contract.js'
import type { ToolkitConfig } from '../config.js'
import { testingOrigins } from '../sites/registry.js'
import type { ContextWithTestId } from '../types/playwright-extensions.js'
import { toolkitRequest } from './toolkit-request.js'

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
    origins: ReadonlySet<string> = testingOrigins(config),
): Promise<void> {
    await context.route(
        () => true,
        async (route) => {
            const headers = { ...route.request().headers() }

            if (origins.has(new URL(route.request().url()).origin)) {
                await route.fallback({ headers: { ...headers, ...browserHeaders(testId) } })

                return
            }

            for (const name of TOOLKIT_HEADERS) {
                delete headers[name]
            }

            await route.fallback({ headers })
        },
    )

    const request = toolkitRequest(context.request, config, testId, (context as ContextWithTestId).site)
    Object.defineProperty(context, 'request', { value: request })
    context.on('page', (page) => Object.defineProperty(page, 'request', { value: request }))
}
