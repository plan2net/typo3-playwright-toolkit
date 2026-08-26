import { test as base, type APIRequestContext, type Browser, type Page, type TestInfo } from '@playwright/test'
import { toolkitHeaders } from './contract.js'
import { getToolkitConfig, type ToolkitConfig } from './config.js'
import { ensureState } from './state/ensure-state.js'
import { applyScenarioOutcome, recordTestFailure } from './state/scenario-outcome.js'
import { sanitizeScenarioKey } from './state/run-namespace.js'
import {
    DEFAULT_BACKEND_PATH,
    resolveRequestContext,
    type ReplayFolder,
    type RequestContext,
} from './builders/request-context.js'
import { recordSaver, type RecordToSave } from './http/record-edit.js'
import { ContentBuilder } from './builders/content-builder.js'
import { PageBuilder } from './builders/page-builder.js'
import { ContextWithTestId, PageWithTestId } from './types/playwright-extensions.js'
import { applyToolkitHeaders } from './http/off-site-headers.js'
import { prepareScenarioContext } from './http/prepare-context.js'
import { toolkitRequest } from './http/toolkit-request.js'
import { runAccessibilityScan, shouldScanAutomatically } from './checks/accessibility.js'

export interface ScenarioBuilders {
    page(): PageBuilder
    content(): ContentBuilder
}

export interface SetupTools {
    testId: string
    attempt: number
    signal: AbortSignal
    page: Page
    request: APIRequestContext
    builders: ScenarioBuilders
    /** For a table no builder covers; returns the uid TYPO3 assigned. */
    saveRecord(record: RecordToSave): Promise<number>
}

export interface ScenarioFixtures<S> {
    state: S
    testId: string
}

interface ResolvedScenario<S> {
    data: S
    testId: string
}

/**
 * What the backend shows beside the site name, so whoever opens an inspect link
 * can tell which scenario they are looking at. The scenario key cannot do that
 * job: it is a sanitised path with a hash on the end.
 */
export function scenarioName(file: string): string {
    const fileName = file.split('/').pop() ?? file
    const stripped = fileName.replace(/\.(spec|test)\.[cm]?[jt]sx?$/i, '')

    return '' === stripped ? fileName : stripped
}

const SYSFOLDER_DOKTYPE = 254
const FIXTURE_ROOT_ID = 1

/** One folder per scenario, so the shared database can be read by a person. */
export async function createScenarioFolder(
    page: Page,
    builderContext: Partial<RequestContext>,
    name: string,
): Promise<ReplayFolder> {
    const folder = await new PageBuilder(page, builderContext)
        .withTitle(name)
        .withField('doktype', SYSFOLDER_DOKTYPE)
        // Or TYPO3 derives one from the title and the scenario's own page,
        // wanting "/news" too, has to take "/news-1".
        .withField('slug', `/replay-${name}`)
        .atParentId(FIXTURE_ROOT_ID)
        .create()

    return { id: folder.id, ownPages: new Set() }
}

export async function buildScenarioContext(
    page: Page,
    config: ToolkitConfig,
    session: { backendPath: string; routeToken: string },
    testId: string,
    name: string,
): Promise<Partial<RequestContext>> {
    const context: Partial<RequestContext> = {
        testId,
        baseUrl: config.testingURL,
        backendPath: session.backendPath,
        routeToken: session.routeToken,
        usedSlugs: new Set(),
    }

    if (!config.replay) {
        return context
    }

    // Built from a context with no folder, or it would land inside itself.
    return { ...context, replayFolder: await createScenarioFolder(page, context, name) }
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
 * One test file is one scenario: the first test that needs state runs the setup,
 * the rest wait for it or are skipped if it failed.
 */
export function defineScenario<S = Record<string, never>>(setup?: (tools: SetupTools) => Promise<S>) {
    return base.extend<
        ScenarioFixtures<S> & { resolvedScenario: ResolvedScenario<S>; automaticAccessibilityScan: void }
    >({
        resolvedScenario: async ({ browser }, use, testInfo: TestInfo) => {
            const config = getToolkitConfig()
            const key = sanitizeScenarioKey(testInfo.file)
            const name = scenarioName(testInfo.file)

            const outcome = await ensureState<S>(config, {
                key,
                name,
                triggerId: testInfo.testId,
                setup: async ({ testId, attempt, signal }) => {
                    if (!setup) {
                        return {} as S
                    }

                    const label = attempt > 1 ? `${name} #${attempt}` : name
                    const session = await openAuthenticatedPage(browser, config, testId, label)
                    try {
                        const builderContext = await buildScenarioContext(
                            session.page,
                            config,
                            session,
                            testId,
                            name,
                        )

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
                            saveRecord: recordSaver(
                                session.page.request,
                                resolveRequestContext(session.page, builderContext),
                            ),
                        })
                    } finally {
                        await session.close()
                    }
                },
            })

            const data = applyScenarioOutcome(
                outcome,
                (reason) => testInfo.skip(true, reason),
                config.replay,
            )

            await use({ data, testId: outcome.status === 'ready' ? outcome.testId : '' })

            if (testInfo.status !== testInfo.expectedStatus) {
                recordTestFailure(config, key, testInfo.error?.message ?? `test ${testInfo.status}`)
            }
        },

        state: async ({ resolvedScenario }, use) => {
            await use(resolvedScenario.data)
        },

        testId: async ({ resolvedScenario }, use) => {
            await use(resolvedScenario.testId)
        },

        context: async ({ context, testId }, use) => {
            await prepareScenarioContext(context, getToolkitConfig(), testId)
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
