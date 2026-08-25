import { defineConfig, type PlaywrightTestConfig } from '@playwright/test'
import type { ToolkitConfig } from '../config.js'

const GLOBAL_SETUP = '@plan2net/typo3-playwright-toolkit/global-setup'
const GLOBAL_TEARDOWN = '@plan2net/typo3-playwright-toolkit/global-teardown'

export function defineBasePlaywrightConfig(
    toolkitConfig: ToolkitConfig,
    overrides: PlaywrightTestConfig = {},
): PlaywrightTestConfig {
    const threshold = toolkitConfig.screenshot?.threshold ?? 0.2
    const maxDiffPixelRatio = toolkitConfig.screenshot?.maxDiffPixelRatio ?? 0.005

    const { use, expect, ...rest } = overrides

    return defineConfig({
        globalSetup: GLOBAL_SETUP,
        globalTeardown: GLOBAL_TEARDOWN,
        fullyParallel: true,
        forbidOnly: !!process.env.CI,
        workers: 2,
        ...rest,
        // Merged key by key, not spread wholesale: Playwright reads `use` and
        // `expect` as whole objects, so overriding one key would drop the base URL,
        // tracing or the screenshot tolerances along with it.
        expect: {
            timeout: 5000,
            ...expect,
            toHaveScreenshot: { maxDiffPixelRatio, threshold, ...expect?.toHaveScreenshot },
            toMatchSnapshot: { maxDiffPixelRatio, threshold, ...expect?.toMatchSnapshot },
        },
        use: {
            baseURL: toolkitConfig.testingURL,
            ignoreHTTPSErrors: true,
            // Serves past context.route, so its requests would carry no test ID.
            serviceWorkers: 'block' as const,
            trace: process.env.CI ? ('retain-on-failure' as const) : ('on-first-retry' as const),
            ...use,
        },
    })
}
