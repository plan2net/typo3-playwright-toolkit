import { fileURLToPath } from 'url'
import { defineToolkitConfig, defineBasePlaywrightConfig } from '@plan2net/typo3-playwright-toolkit/playwright'

const toolkit = defineToolkitConfig({
    testingURL: 'https://t3pw-e2e-testing.ddev.site',
    backendPath: process.env.PW_E2E_BACKEND || undefined,
    paths: { consumerRoot: fileURLToPath(new URL('../..', import.meta.url)) },
})

// No projects: one default chromium run. This job answers whether the three
// packages compose, not how the site renders across browsers.
export default defineBasePlaywrightConfig(toolkit, { testDir: './tests' })
