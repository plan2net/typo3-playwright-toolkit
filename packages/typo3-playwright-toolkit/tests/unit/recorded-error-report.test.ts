import type { TestInfo } from '@playwright/test'
import { describe, expect, it } from 'vitest'
import { reportRecordedErrors } from '#src/report/recorded-error-report.js'
import type { ToolkitConfig } from '#src/config.js'

const config = {
    testingURL: 'https://example-testing.ddev.site',
    replay: false,
    paths: { consumerRoot: '/app' },
} as ToolkitConfig

function fakeTestInfo(file?: string): { testInfo: TestInfo; attached: string[] } {
    const attached: string[] = []
    const testInfo = {
        file,
        attach: async (name: string) => {
            attached.push(name)
        },
    } as unknown as TestInfo

    return { testInfo, attached }
}

const oneError = async () => ({
    testId: 'K7F2QX9M4TB6WZ1P',
    truncated: false,
    errors: [
        {
            uid: 1,
            source: 'php',
            at: '2026-08-29T09:14:21+00:00',
            message: 'No page configured for type=99999.',
            count: 1,
        },
    ],
})

describe('reportRecordedErrors', () => {
    it('attaches what TYPO3 recorded and throws it so the report shows it', async () => {
        const { testInfo, attached } = fakeTestInfo()
        const fetcher = async () => ({
            testId: 'K7F2QX9M4TB6WZ1P',
            truncated: false,
            errors: [
                {
                    uid: 1,
                    source: 'php',
                    at: '2026-08-29T09:14:21+00:00',
                    message: 'No page configured for type=99999.',
                    count: 4,
                },
            ],
        })

        await expect(
            reportRecordedErrors(config, 'pages/teaser', 'K7F2QX9M4TB6WZ1P', testInfo, fetcher),
        ).rejects.toThrow(/TYPO3 recorded 1 error for scenario "pages\/teaser"/)

        expect(attached).toEqual(['typo3-errors.json'])
    })

    it('names the spec file, not the sanitised scenario key', async () => {
        const { testInfo } = fakeTestInfo('/app/test/playwright/tests/teaser.spec.ts')

        await expect(
            reportRecordedErrors(
                config,
                '_var_www_html_test_playwright_tests_teaser_spec_ts-fd3158a3',
                'K7F2QX9M4TB6WZ1P',
                testInfo,
                oneError,
            ),
        ).rejects.toThrow('for scenario "test/playwright/tests/teaser.spec.ts"')
    })
})
