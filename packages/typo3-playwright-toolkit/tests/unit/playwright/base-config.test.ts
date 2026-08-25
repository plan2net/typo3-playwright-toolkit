import { describe, expect, it } from 'vitest'
import { defineBasePlaywrightConfig } from '#src/playwright/base-config.js'
import type { ToolkitConfig } from '#src/config.js'

function toolkitConfig(): ToolkitConfig {
    return {
        testingURL: 'https://example-testing.test',
        contentTypes: {},
        paths: {
            consumerRoot: '/srv/project',
            stateDir: '/srv/project/.test-state',
            sessionDir: '/srv/project/var/session',
        },
        screenshot: { threshold: 0.3, maxDiffPixelRatio: 0.02 },
    }
}

describe('defineBasePlaywrightConfig', () => {
    // A relative page.goto() has to land on the site the test database is wired
    // into. The other origin runs outside the Testing context, so the extension
    // is gated off there and the content the setup built does not exist.
    it('points relative navigation at the testing URL', () => {
        const config = defineBasePlaywrightConfig(toolkitConfig(), { testDir: './tests' })

        expect(config.use?.baseURL).toBe('https://example-testing.test')
    })

    it('takes the screenshot tolerances from the toolkit config', () => {
        const config = defineBasePlaywrightConfig(toolkitConfig(), { testDir: './tests' })

        expect(config.expect?.toHaveScreenshot).toMatchObject({ threshold: 0.3, maxDiffPixelRatio: 0.02 })
    })

    it('defaults the global hooks to the package entry points', () => {
        const config = defineBasePlaywrightConfig(toolkitConfig(), { testDir: './tests' })

        expect(config.globalSetup).toBe('@plan2net/typo3-playwright-toolkit/global-setup')
        expect(config.globalTeardown).toBe('@plan2net/typo3-playwright-toolkit/global-teardown')
    })

    it('lets a consumer override the global hooks', () => {
        const config = defineBasePlaywrightConfig(toolkitConfig(), { globalSetup: './my-setup.ts' })

        expect(config.globalSetup).toBe('./my-setup.ts')
    })

    // A consumer setting one `use` option must not silently lose the base URL,
    // tracing and TLS handling along with it.
    it('merges use options instead of replacing the block', () => {
        const config = defineBasePlaywrightConfig(toolkitConfig(), {
            use: { locale: 'de-AT' },
        })

        expect(config.use?.locale).toBe('de-AT')
        expect(config.use?.baseURL).toBe('https://example-testing.test')
        expect(config.use?.trace).toBeDefined()
    })

    it('merges expect options instead of replacing the block', () => {
        const config = defineBasePlaywrightConfig(toolkitConfig(), {
            expect: { timeout: 10_000 },
        })

        expect(config.expect?.timeout).toBe(10_000)
        expect(config.expect?.toHaveScreenshot).toMatchObject({ threshold: 0.3 })
    })

    it('merges the screenshot comparators one level deeper', () => {
        const config = defineBasePlaywrightConfig(toolkitConfig(), {
            expect: { toHaveScreenshot: { animations: 'disabled' } },
        })

        expect(config.expect?.toHaveScreenshot).toMatchObject({
            animations: 'disabled',
            threshold: 0.3,
        })
    })

    it('still lets a consumer override a merged leaf', () => {
        const config = defineBasePlaywrightConfig(toolkitConfig(), {
            use: { baseURL: 'https://other.test' },
        })

        expect(config.use?.baseURL).toBe('https://other.test')
    })

    it('leaves TLS verification to the consumer rather than pinning it off', () => {
        const config = defineBasePlaywrightConfig(toolkitConfig(), {
            use: { ignoreHTTPSErrors: false },
        })

        expect(config.use?.ignoreHTTPSErrors).toBe(false)
    })

    it('blocks service workers, which routing cannot reach', () => {
        const config = defineBasePlaywrightConfig(toolkitConfig())

        expect(config.use?.serviceWorkers).toBe('block')
    })

    it('still lets a consumer allow them deliberately', () => {
        const config = defineBasePlaywrightConfig(toolkitConfig(), {
            use: { serviceWorkers: 'allow' },
        })

        expect(config.use?.serviceWorkers).toBe('allow')
    })
})
