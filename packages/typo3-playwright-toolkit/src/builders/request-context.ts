import { getToolkitConfig } from '../config.js'
import { ContextWithTestId, PageWithTestId } from '../types/playwright-extensions.js'

export interface RequestContext {
    baseUrl: string
    testId: string
    /** For `record_edit`; the session endpoint hands it out. */
    routeToken: string
}

export interface RequestContextSource {
    url(): string
    context(): unknown
}

/**
 * The fixtures put the test ID on the page and context; an explicit one overrides
 * it. An empty ID is refused rather than sent, because the extension reads an
 * empty header as "use the base database".
 */
export function ambientTestId(page: RequestContextSource): string | undefined {
    return (
        (page as unknown as PageWithTestId).testId ||
        (page.context() as ContextWithTestId).testId ||
        undefined
    )
}

export function requireTestId(page: RequestContextSource, explicit?: string): string {
    const testId = explicit || ambientTestId(page) || ''

    if (!testId) {
        throw new Error(
            '[typo3-playwright-toolkit] No test ID for this request. Pass one in, or use a page from the ' +
                'toolkit fixtures — without it the request would run against the base database.',
        )
    }

    return testId
}

export function resolveRequestContext(
    page: RequestContextSource,
    explicit: Partial<RequestContext> = {},
): RequestContext {
    return {
        // Never derived from page.url(): the request carries the API secret, and a
        // page that navigated off-site must not decide where the builder posts it.
        baseUrl: explicit.baseUrl ?? getToolkitConfig().testingURL,
        testId: requireTestId(page, explicit.testId),
        routeToken: explicit.routeToken ?? '',
    }
}
