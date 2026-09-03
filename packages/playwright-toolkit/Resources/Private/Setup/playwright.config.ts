import { fileURLToPath } from 'url'
import { defineToolkitConfig, defineBasePlaywrightConfig } from '@plan2net/typo3-playwright-toolkit/playwright'

const toolkit = defineToolkitConfig({
    testingURL: '{{TESTING_URL}}',
    paths: { consumerRoot: fileURLToPath(new URL('{{PROJECT_ROOT}}', import.meta.url)) },
})

export default defineBasePlaywrightConfig(toolkit, { testDir: './tests' })
