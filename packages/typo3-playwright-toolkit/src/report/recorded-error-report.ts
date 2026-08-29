import * as path from 'path'
import type { TestInfo } from '@playwright/test'
import type { ToolkitConfig } from '../config.js'
import {
    fetchRecordedErrors,
    formatRecordedErrors,
    type RecordedErrorReport,
} from '../http/recorded-errors.js'

export type RecordedErrorFetcher = (
    config: ToolkitConfig,
    testId: string,
) => Promise<RecordedErrorReport | undefined>

/** Thrown, not only attached, so it prints beside the failure already there. */
export async function reportRecordedErrors(
    config: ToolkitConfig,
    scenarioKey: string,
    testId: string,
    testInfo: TestInfo,
    fetcher: RecordedErrorFetcher = fetchRecordedErrors,
): Promise<void> {
    if (!testId || config.replay) {
        return
    }

    const report = await fetcher(config, testId)
    if (!report || 0 === report.errors.length) {
        return
    }

    await testInfo.attach('typo3-errors.json', {
        body: Buffer.from(JSON.stringify(report, null, 2)),
        contentType: 'application/json',
    })

    // The scenario key is a sanitised absolute path; the spec file reads better.
    const label = testInfo.file ? path.relative(config.paths.consumerRoot, testInfo.file) : scenarioKey

    throw new Error(formatRecordedErrors(label, report))
}
