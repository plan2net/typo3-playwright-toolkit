import type { APIResponse, BrowserContext, Route } from '@playwright/test'
import { SECRET_HEADER } from './api-secret.js'
import { TEST_ID_HEADER, browserHeaders } from '../contract.js'
import { getToolkitConfig, type ToolkitConfig } from '../config.js'
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
            const request = route.request()
            await route.fallback({ headers: routedHeaders(request.url(), request.headers(), testId, origins) })
        },
    )

    const request = toolkitRequest(context.request, config, testId, (context as ContextWithTestId).site)
    Object.defineProperty(context, 'request', { value: request })
    context.on('page', (page) => Object.defineProperty(page, 'request', { value: request }))
}

/**
 * route.fetch() with the test ID, for a test's own page.route() handler. That handler
 * runs before the toolkit adds the ID, so a plain fetch would reach the site's normal
 * database. Like every other request, only the site under test gets the ID.
 */
export function fetchForTest(
    route: Route,
    testId: string,
    options: NonNullable<Parameters<Route['fetch']>[0]> = {},
): Promise<APIResponse> {
    const request = route.request()
    const headers = { ...request.headers(), ...options.headers }
    // The options may send the request to another address; that address decides
    const url = options.url ?? request.url()

    return route.fetch({
        ...options,
        headers: routedHeaders(url, headers, testId, testingOrigins(getToolkitConfig())),
    })
}

function routedHeaders(
    url: string,
    requested: Record<string, string>,
    testId: string,
    origins: ReadonlySet<string>,
): Record<string, string> {
    const headers = { ...requested }

    if (origins.has(new URL(url).origin)) {
        return { ...headers, ...browserHeaders(testId) }
    }

    // Header names a test passes may be spelled in any case
    for (const name of Object.keys(headers)) {
        if (TOOLKIT_HEADERS.includes(name.toLowerCase())) {
            delete headers[name]
        }
    }

    return headers
}
