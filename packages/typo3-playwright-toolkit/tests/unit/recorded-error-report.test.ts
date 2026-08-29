import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import type { TestInfo } from '@playwright/test'
import { describe, expect, it } from 'vitest'
import { recordScenarioFailure } from '#src/state/scenario-state.js'
import {
    failedAttemptTestId,
    reportRecordedErrors,
    reportSetupFailure,
} from '#src/report/recorded-error-report.js'
import type { ScenarioFailureRecord } from '#src/state/scenario-state.js'
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

describe('reportSetupFailure', () => {
    it('keeps the original message and stack, and adds what TYPO3 recorded', async () => {
        const { testInfo, attached } = fakeTestInfo('/app/test/playwright/tests/teaser.spec.ts')
        const original = new Error('pages did not save (status 200)')
        original.stack = 'Error: pages did not save (status 200)\n    at PageBuilder.create (builders/page-builder.ts:99:29)'

        const thrown = await reportSetupFailure(config, 'teaser', testInfo, original, {
            testId: 'K7F2QX9M4TB6WZ1P',
            fetcher: oneError,
        }).catch((error: Error) => error)

        expect(thrown.message).toContain('pages did not save (status 200)')
        expect(thrown.message).toContain('No page configured for type=99999.')
        expect(thrown.stack).toContain('at PageBuilder.create (builders/page-builder.ts:99:29)')
        // Playwright prints a cause in full, which would repeat the whole message.
        expect(thrown.cause).toBeUndefined()
        expect(attached).toEqual(['typo3-errors.json'])
    })

    it('finds the failed attempt itself when no test id is given', async () => {
        const tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-setup-failure-'))
        const onDisk = {
            ...config,
            runId: 'run-for-the-lookup',
            paths: { ...config.paths, stateDir: path.join(tmpRoot, '.test-state') },
        } as ToolkitConfig
        recordScenarioFailure(onDisk, {
            key: 'teaser',
            scenarioKey: 'teaser',
            triggeringTestId: 'whoever',
            attempts: [
                { attempt: 1, testId: 'K7F2QX9M4TB6WZ1P', error: 'it broke', durationMs: 1 },
            ],
        })

        const { testInfo } = fakeTestInfo('/app/test/playwright/tests/teaser.spec.ts')
        const asked: string[] = []
        const thrown = await reportSetupFailure(
            onDisk,
            'teaser',
            testInfo,
            new Error('it broke'),
            {
                fetcher: async (_config, testId) => {
                    asked.push(testId)

                    return oneError()
                },
            },
        ).catch((error: Error) => error)

        expect(asked).toEqual(['K7F2QX9M4TB6WZ1P'])
        expect(thrown.message).toContain('No page configured for type=99999.')
    })

    it('asks about the last attempt that had a database', () => {
        expect(
            failedAttemptTestId({
                attempts: [
                    { attempt: 1, testId: 'AAAA1111AAAA1111', error: 'first', durationMs: 1 },
                    { attempt: 2, testId: '', error: 'died before provisioning', durationMs: 1 },
                ],
            } as ScenarioFailureRecord),
        ).toBe('AAAA1111AAAA1111')
    })
})
