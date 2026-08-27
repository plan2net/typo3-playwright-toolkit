import { describe, expect, it } from 'vitest'
import { defineBasePlaywrightConfig, type BasePlaywrightOverrides } from '#src/playwright/base-config.js'
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

    it('refuses a globalSetup override', () => {
        const overrides = { globalSetup: './my-setup.ts' } as BasePlaywrightOverrides

        expect(() => defineBasePlaywrightConfig(toolkitConfig(), overrides)).toThrow(/globalSetup/)
    })

    it('refuses a globalTeardown override', () => {
        const overrides = { globalTeardown: './my-teardown.ts' } as BasePlaywrightOverrides

        expect(() => defineBasePlaywrightConfig(toolkitConfig(), overrides)).toThrow(/globalTeardown/)
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

    it('refuses a baseURL override', () => {
        const overrides = { use: { baseURL: 'https://other.test' } } as unknown as BasePlaywrightOverrides

        expect(() => defineBasePlaywrightConfig(toolkitConfig(), overrides)).toThrow(/baseURL/)
    })

    // Playwright merges a project's `use` over the top-level one.
    it('refuses a baseURL override inside a project', () => {
        const overrides = {
            projects: [{ name: 'chromium', use: { baseURL: 'https://other.test' } }],
        } as unknown as BasePlaywrightOverrides

        expect(() => defineBasePlaywrightConfig(toolkitConfig(), overrides)).toThrow(/baseURL/)
    })

    it('refuses a serviceWorkers override inside a project', () => {
        const overrides = {
            projects: [{ name: 'chromium' }, { name: 'mobile', use: { serviceWorkers: 'allow' } }],
        } as unknown as BasePlaywrightOverrides

        expect(() => defineBasePlaywrightConfig(toolkitConfig(), overrides)).toThrow(/serviceWorkers/)
    })

    it('still lets a project set its own unprotected use options', () => {
        const config = defineBasePlaywrightConfig(toolkitConfig(), {
            projects: [{ name: 'mobile', use: { locale: 'de-AT' } }],
        })

        expect(config.projects?.[0]?.use?.locale).toBe('de-AT')
    })

    it('still lets a consumer override an unprotected merged leaf', () => {
        const config = defineBasePlaywrightConfig(toolkitConfig(), {
            use: { trace: 'off' },
        })

        expect(config.use?.trace).toBe('off')
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

    it('refuses a serviceWorkers override', () => {
        const overrides = { use: { serviceWorkers: 'allow' } } as unknown as BasePlaywrightOverrides

        expect(() => defineBasePlaywrightConfig(toolkitConfig(), overrides)).toThrow(/serviceWorkers/)
    })
})
