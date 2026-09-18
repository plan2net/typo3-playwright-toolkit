import { defineConfig, type PlaywrightTestConfig } from '@playwright/test'
import type { ToolkitConfig } from '../config.js'

const GLOBAL_SETUP = '@plan2net/typo3-playwright-toolkit/global-setup'
const GLOBAL_TEARDOWN = '@plan2net/typo3-playwright-toolkit/global-teardown'
const DEFAULT_MAX_DIFF_PIXELS = 20

type UseOptions = NonNullable<PlaywrightTestConfig['use']>
type ProjectConfig = NonNullable<PlaywrightTestConfig['projects']>[number]

type ProtectedUse = Omit<UseOptions, 'baseURL' | 'serviceWorkers'>

interface ScreenshotTolerance {
    threshold?: number
    maxDiffPixels?: number
    maxDiffPixelRatio?: number
}

/**
 * Playwright's config without the four keys the toolkit depends on: the global
 * hooks run the preflight and the cleanup, and the other two keys are needed for
 * the test ID routing. A project's `use` is merged over the top-level one, so it
 * is restricted the same way.
 */
export type BasePlaywrightOverrides = Omit<PlaywrightTestConfig, 'globalSetup' | 'globalTeardown' | 'use' | 'projects'> & {
    use?: ProtectedUse
    projects?: Array<Omit<ProjectConfig, 'use'> & { use?: ProtectedUse }>
}

export function defineBasePlaywrightConfig(
    toolkitConfig: ToolkitConfig,
    overrides: BasePlaywrightOverrides = {},
): PlaywrightTestConfig {
    refuseProtectedOverrides(overrides)

    const tolerance = screenshotTolerance(toolkitConfig.screenshot)

    const { use, expect, ...rest } = overrides

    return defineConfig({
        fullyParallel: true,
        forbidOnly: !!process.env.CI,
        workers: 2,
        // Playwright's own default writes no report, leaving the add-on's report port
        // and `show-report` nothing to serve. Never opened: the container has no browser.
        reporter: [['list'], ['html', { open: 'never' }]],
        ...rest,
        globalSetup: GLOBAL_SETUP,
        globalTeardown: GLOBAL_TEARDOWN,
        // Merged key by key, not spread wholesale: Playwright reads `use` and
        // `expect` as whole objects, so overriding one key would drop the base URL,
        // tracing or the screenshot tolerances along with it.
        expect: {
            timeout: 5000,
            ...expect,
            toHaveScreenshot: withTolerance(tolerance, expect?.toHaveScreenshot),
            toMatchSnapshot: withTolerance(tolerance, expect?.toMatchSnapshot),
        },
        use: {
            ignoreHTTPSErrors: true,
            trace: 'retain-on-failure' as const,
            ...use,
            baseURL: toolkitConfig.testingURL,
            // Serves past context.route, so its requests would carry no test ID.
            serviceWorkers: 'block' as const,
        },
    })
}

/**
 * `threshold` absorbs antialiasing noise per pixel; what is left is a few stray
 * pixels, so the allowance is a fixed count. A share of the image grows with the
 * shot and can hide a missing button in a full-page one. Setting a ratio replaces
 * the count.
 */
function screenshotTolerance(screenshot: ToolkitConfig['screenshot']): ScreenshotTolerance {
    const threshold = screenshot?.threshold ?? 0.2

    if (screenshot?.maxDiffPixels !== undefined || screenshot?.maxDiffPixelRatio !== undefined) {
        return { threshold, maxDiffPixels: screenshot.maxDiffPixels, maxDiffPixelRatio: screenshot.maxDiffPixelRatio }
    }

    return { threshold, maxDiffPixels: DEFAULT_MAX_DIFF_PIXELS }
}

/**
 * Playwright applies both allowances and keeps the smaller one, so the default
 * count has to go when the caller asks for a ratio, or it caps what they asked for.
 */
function withTolerance<T extends ScreenshotTolerance>(tolerance: ScreenshotTolerance, options: T | undefined): T {
    if (options?.maxDiffPixels !== undefined || options?.maxDiffPixelRatio !== undefined) {
        return { ...tolerance, maxDiffPixels: undefined, ...options }
    }

    return { ...tolerance, ...options } as T
}

// The type already excludes these, but a JS consumer or a cast gets past it.
function refuseProtectedOverrides(overrides: BasePlaywrightOverrides): void {
    const config = overrides as PlaywrightTestConfig
    if (config.globalSetup !== undefined) {
        refuse('globalSetup', 'the toolkit setup runs the preflight and the run bookkeeping cleanup depends on; add your own setup as a Playwright project dependency instead')
    }
    if (config.globalTeardown !== undefined) {
        refuse('globalTeardown', 'the toolkit teardown drops this run\'s test databases — without it every run leaks them')
    }
    refuseProtectedUse('use', config.use)
    for (const [index, project] of (config.projects ?? []).entries()) {
        refuseProtectedUse(`projects[${index}].use`, project.use)
    }
}

function refuseProtectedUse(where: string, use: UseOptions | undefined): void {
    if (use?.baseURL !== undefined) {
        refuse(`${where}.baseURL`, 'testingURL is the only URL; set it in defineToolkitConfig')
    }
    if (use?.serviceWorkers !== undefined) {
        refuse(`${where}.serviceWorkers`, 'service workers serve past the routing that carries the test ID, so they stay blocked')
    }
}

function refuse(option: string, why: string): never {
    throw new Error(`[typo3-playwright-toolkit] overrides.${option} cannot be overridden: ${why}`)
}
