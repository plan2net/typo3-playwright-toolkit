import * as path from 'path'
import type { BrowserContext } from '@playwright/test'
import type { ContentBuilderInterface } from './types/content-builder.js'
import { registerContentTypes } from './builders/content-factory.js'
import { resolveRunId } from './state/run-id.js'
import { assertDeletableDirectory } from './state/safe-paths.js'

export type ContentTypeConstructor = new () => ContentBuilderInterface

export interface ToolkitScreenshotConfig {
    threshold?: number
    maxDiffPixelRatio?: number
}

export interface ToolkitAccessibilityConfig {
    /**
     * Projects to scan; omit for all. Worth narrowing: axe evaluates the rendered
     * state, so desktop and mobile surface different violations while a tablet
     * project or a second engine adds cost without coverage.
     */
    projects?: string[]
    /** Overrides DEFAULT_SCAN_TAGS. */
    tags?: string[]
    disabledRules?: string[]
    /**
     * Scan after every test that opened a page, instead of calling
     * runAccessibilityScan yourself. A test that already failed is left alone.
     */
    auto?: boolean
}

export interface ToolkitCspConfig {
    /**
     * `any` (default) requires some policy header, `report-only` also refuses an
     * enforcing one, `enforced` requires it and tolerates report-only alongside.
     */
    mode?: 'any' | 'report-only' | 'enforced'
    /** Defaults to the origin of testingURL. */
    expectedOrigin?: string
}

/** All three are absolute, and both writable ones must sit inside consumerRoot. */
export interface ToolkitPaths {
    consumerRoot: string
    stateDir: string
    /** TYPO3 session directory; teardown deletes stale entries from it. */
    sessionDir: string
}

/** Every default lives in SETUP_DEFAULTS (ensure-state.ts), which is exported. */
export interface ToolkitSetupConfig {
    /** One setup attempt. */
    attemptTimeoutMs?: number
    /** Whole wait for a scenario, lock waiting plus every attempt. */
    waitTimeoutMs?: number
    /** Total attempts, so 2 means one retry. */
    attempts?: number
    /** Silence after which a holder's lock may be taken. */
    lockStaleMs?: number
    /** Gap between polls while waiting. */
    pollMs?: number
}

export interface ToolkitCleanupConfig {
    /** Age after which unrecorded databases and abandoned runs are reclaimed. */
    orphanAgeMs?: number
    /** Fail the run when a test database survives teardown. Defaults to true in CI. */
    failOnLeak?: boolean
    /** Which databases to keep when something failed. Default `failed`. */
    preserveOnFailure?: 'failed' | 'all' | 'none'
}

export interface ToolkitConfig {
    /**
     * The only URL the toolkit uses: the origin that serves the Testing context.
     * Relative navigation, the test API and the builders all resolve against it,
     * because the per-test database exists nowhere else. A bare origin — no path,
     * query, fragment or credentials; `defineToolkitConfig` refuses anything else.
     */
    testingURL: string
    /** Screenshot tolerances merged into the base Playwright config + Screenshot helper. */
    screenshot?: ToolkitScreenshotConfig
    /**
     * Your own content-type builders, keyed by CType. Core CTypes (text,
     * textmedia, bullets, table, …) ship with the toolkit and need no entry; a key
     * matching one of them replaces the shipped builder.
     */
    contentTypes?: Record<string, ContentTypeConstructor>
    /** Selectors hidden before each screenshot. Defaults to []. */
    hideBeforeScreenshot?: string[]
    /**
     * Runs on every context a scenario test uses, after the toolkit's own headers are
     * in place. Where a consumer stubs a third-party script or adds its own routes.
     */
    prepareContext?: (context: BrowserContext) => Promise<void> | void
    /** Relocatability paths — never derived from __dirname. */
    paths: ToolkitPaths
    /** Pins the run ID. Normally omitted — taken from PW_RUN_ID or minted. */
    runId?: string
    /** Set by PW_REPLAY=1: run every setup into the base database instead of per-test ones. */
    replay?: boolean
    cleanup?: ToolkitCleanupConfig
    accessibility?: ToolkitAccessibilityConfig
    csp?: ToolkitCspConfig
    setup?: ToolkitSetupConfig
}

/** What a consumer writes: only consumerRoot is required, the other two are derived from it. */
export type ToolkitConfigInput = Omit<ToolkitConfig, 'paths'> & {
    paths: Pick<ToolkitPaths, 'consumerRoot'> & Partial<ToolkitPaths>
}

let activeConfig: ToolkitConfig | undefined

function resolvePaths(paths: ToolkitConfigInput['paths']): ToolkitPaths {
    if (!path.isAbsolute(paths.consumerRoot)) {
        throw new Error(
            `[typo3-playwright-toolkit] paths.consumerRoot must be an absolute path, got "${paths.consumerRoot}".`,
        )
    }

    const resolved = {
        consumerRoot: paths.consumerRoot,
        stateDir: paths.stateDir ?? path.join(paths.consumerRoot, '.test-state'),
        sessionDir: paths.sessionDir ?? path.join(paths.consumerRoot, 'var/session'),
    }

    assertDeletableDirectory('stateDir', resolved.stateDir, resolved.consumerRoot)
    assertDeletableDirectory('sessionDir', resolved.sessionDir, resolved.consumerRoot)

    return resolved
}

function resolveTestingURL(testingURL: string): string {
    const refuse = (reason: string): never => {
        throw new Error(`[typo3-playwright-toolkit] testingURL ${reason}, got "${testingURL}".`)
    }

    let url: URL
    try {
        url = new URL(testingURL)
    } catch {
        return refuse('must be an absolute URL, such as https://example-testing.ddev.site')
    }

    if ('https:' !== url.protocol && 'http:' !== url.protocol) {
        return refuse('must be http or https')
    }
    if (url.username || url.password) {
        return refuse('must carry no credentials')
    }
    if (url.search || url.hash) {
        return refuse('must carry no query string or fragment')
    }
    // The test API answers at /typo3/test-api/…, so a subdirectory install cannot
    // work and dropping the path silently would hide that.
    if (!/^\/+$/.test(url.pathname)) {
        return refuse('must be a bare origin, with no path')
    }

    return url.origin
}

export function defineToolkitConfig(config: ToolkitConfigInput): ToolkitConfig {
    // Resolved here: this runs in the main process before workers are forked.
    const resolved: ToolkitConfig = {
        ...config,
        testingURL: resolveTestingURL(config.testingURL),
        paths: resolvePaths(config.paths),
        runId: resolveRunId(config.runId),
        replay: '1' === process.env.PW_REPLAY,
    }
    setToolkitConfig(resolved)
    registerContentTypes(config.contentTypes ?? {})
    return resolved
}

export function setToolkitConfig(config: ToolkitConfig): void {
    activeConfig = config
}

export function getToolkitConfig(): ToolkitConfig {
    if (!activeConfig) {
        throw new Error(
            '[typo3-playwright-toolkit] No toolkit config set. Call defineToolkitConfig(...) ' +
                'in your Playwright config before the toolkit modules run.',
        )
    }
    return activeConfig
}
