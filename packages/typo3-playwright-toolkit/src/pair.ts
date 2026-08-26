import { test as base, type APIRequestContext, type Browser, type Page, type TestInfo } from '@playwright/test'
import { toolkitHeaders } from './contract.js'
import { getToolkitConfig, type ToolkitConfig } from './config.js'
import { ensureState } from './state/ensure-state.js'
import { applyPairOutcome, recordPairVerifyFailure } from './state/pair-outcome.js'
import { sanitizePairKey } from './state/run-namespace.js'
import { DEFAULT_BACKEND_PATH } from './builders/request-context.js'
import { ContentBuilder } from './builders/content-builder.js'
import { PageBuilder } from './builders/page-builder.js'
import { ContextWithTestId, PageWithTestId } from './types/playwright-extensions.js'
import { applyToolkitHeaders } from './http/off-site-headers.js'
import { preparePairContext } from './http/prepare-context.js'
import { toolkitRequest } from './http/toolkit-request.js'
import { runAccessibilityScan, shouldScanAutomatically } from './checks/accessibility.js'

export interface PairBuilders {
    page(): PageBuilder
    content(): ContentBuilder
}

export interface SetupTools {
    testId: string
    attempt: number
    signal: AbortSignal
    page: Page
    request: APIRequestContext
    builders: PairBuilders
}

export interface PairFixtures<S> {
    state: S
    testId: string
}

interface ResolvedPair<S> {
    data: S
    testId: string
}

export function testName(key: string, attempt: number): string {
    return attempt > 1 ? `${key} #${attempt}` : key
}

export async function openAuthenticatedPage(
    browser: Browser,
    config: ToolkitConfig,
    testId: string,
    name = '',
): Promise<{ page: Page; routeToken: string; backendPath: string; close: () => Promise<void> }> {
    const headers = toolkitHeaders(config, testId)
    const context = await browser.newContext({ ignoreHTTPSErrors: true })

    // The close handle is only returned on success, so a failure closes its own context.
    try {
        await applyToolkitHeaders(context, config, testId)
        ;(context as ContextWithTestId).testId = testId

        const page = await context.newPage()
        ;(page as PageWithTestId).testId = testId

        const response = await page.request.post(`${config.testingURL}/typo3/test-api/session`, {
            headers,
            data: { name },
        })
        if (!response.ok()) {
            throw new Error(`[setup] Session creation failed (${response.status()}): ${await response.text()}`)
        }

        const session = (await response.json()) as {
            cookieName: string
            cookieValue: string
            backendPath?: string
            tokens?: Record<string, string>
        }
        await context.addCookies([
            {
                name: session.cookieName,
                value: session.cookieValue,
                domain: new URL(config.testingURL).hostname,
                path: '/',
                httpOnly: true,
                sameSite: 'Strict',
            },
        ])

        return {
            page,
            routeToken: session.tokens?.record_edit ?? '',
            backendPath: session.backendPath ?? DEFAULT_BACKEND_PATH,
            close: () => context.close(),
        }
    } catch (error) {
        await context.close()

        throw error
    }
}

/**
 * One test file is one pair: the first test that needs state runs the setup,
 * the rest wait for it or are skipped if it failed.
 */
export function definePair<S = Record<string, never>>(setup?: (tools: SetupTools) => Promise<S>) {
    return base.extend<
        PairFixtures<S> & { resolvedPair: ResolvedPair<S>; automaticAccessibilityScan: void }
    >({
        resolvedPair: async ({ browser }, use, testInfo: TestInfo) => {
            const config = getToolkitConfig()
            const key = sanitizePairKey(testInfo.file)

            const outcome = await ensureState<S>(config, {
                key,
                triggerId: testInfo.testId,
                setup: async ({ testId, attempt, signal }) => {
                    if (!setup) {
                        return {} as S
                    }

                    const session = await openAuthenticatedPage(browser, config, testId, testName(key, attempt))
                    const builderContext = {
                        testId,
                        baseUrl: config.testingURL,
                        backendPath: session.backendPath,
                        routeToken: session.routeToken,
                    }
                    try {
                        return await setup({
                            testId,
                            attempt,
                            signal,
                            page: session.page,
                            request: toolkitRequest(session.page.request, config, testId),
                            builders: {
                                page: () => new PageBuilder(session.page, builderContext),
                                content: () => new ContentBuilder(session.page, builderContext),
                            },
                        })
                    } finally {
                        await session.close()
                    }
                },
            })

            const data = applyPairOutcome(outcome, (reason) => testInfo.skip(true, reason))

            await use({ data, testId: outcome.status === 'ready' ? outcome.testId : '' })

            if (testInfo.status !== testInfo.expectedStatus) {
                recordPairVerifyFailure(config, key, testInfo.error?.message ?? `test ${testInfo.status}`)
            }
        },

        state: async ({ resolvedPair }, use) => {
            await use(resolvedPair.data)
        },

        testId: async ({ resolvedPair }, use) => {
            await use(resolvedPair.testId)
        },

        context: async ({ context, testId }, use) => {
            await preparePairContext(context, getToolkitConfig(), testId)
            await use(context)
        },

        request: async ({ request, testId }, use) => {
            await use(toolkitRequest(request, getToolkitConfig(), testId))
        },

        automaticAccessibilityScan: [
            async ({ page }, use, testInfo: TestInfo) => {
                await use()

                const config = getToolkitConfig()
                const navigated = page.url().startsWith('http')

                if (shouldScanAutomatically({ status: testInfo.status ?? '', navigated }, config.accessibility)) {
                    await runAccessibilityScan(page)
                }
            },
            { auto: true },
        ],
    })
}
