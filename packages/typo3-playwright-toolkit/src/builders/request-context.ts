import { getToolkitConfig } from '../config.js'
import { ContextWithTestId, PageWithTestId } from '../types/playwright-extensions.js'

/** TYPO3's own default for `BE/entryPoint`, which 11.5 and 12.4 have no setting for. */
export const DEFAULT_BACKEND_PATH = '/typo3'

/** Replay only: the scenario's folder and the pages it created inside it. */
export interface ReplayFolder {
    id: string
    ownPages: Set<string>
}

export interface RequestContext {
    baseUrl: string
    /** Where the backend routes answer, from `BE/entryPoint`; the session endpoint reports it. */
    backendPath: string
    testId: string
    /** For `record_edit`; the session endpoint hands it out. */
    routeToken: string
    replayFolder?: ReplayFolder
    /** Slugs this scenario already created. */
    usedSlugs?: Set<string>
}

/** A fixture page as parent means the record moves into the folder; one the scenario made keeps it. */
export function replayParentId(context: RequestContext, parentId: string): string {
    const folder = context.replayFolder

    return folder && !folder.ownPages.has(parentId) ? folder.id : parentId
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
        replayFolder: explicit.replayFolder,
        usedSlugs: explicit.usedSlugs,
        // Never derived from page.url(): the request carries the API secret, and a
        // page that navigated off-site must not decide where the builder posts it.
        baseUrl: explicit.baseUrl ?? getToolkitConfig().testingURL,
        backendPath: explicit.backendPath ?? DEFAULT_BACKEND_PATH,
        testId: requireTestId(page, explicit.testId),
        routeToken: explicit.routeToken ?? '',
    }
}
