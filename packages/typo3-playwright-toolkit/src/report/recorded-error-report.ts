import * as path from 'path'
import type { TestInfo } from '@playwright/test'
import type { ToolkitConfig } from '../config.js'
import {
    fetchRecordedErrors,
    formatRecordedErrors,
    type RecordedErrorReport,
} from '../http/recorded-errors.js'
import { readScenarioFailure, type ScenarioFailureRecord } from '../state/scenario-state.js'

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
    const summary = await collectSummary(config, scenarioKey, testId, testInfo, fetcher)

    if (summary) {
        throw new Error(summary)
    }
}

/** A retry that died before provisioning has no database to ask about. */
export function failedAttemptTestId(record: ScenarioFailureRecord | undefined): string {
    const withDatabase = (record?.attempts ?? []).filter((attempt) => attempt.testId)

    return withDatabase.at(-1)?.testId ?? ''
}

/**
 * The setup already failed with an error worth keeping, so its message and its
 * frames are carried over rather than replaced.
 */
export async function reportSetupFailure(
    config: ToolkitConfig,
    scenarioKey: string,
    testInfo: TestInfo,
    original: unknown,
    options: { testId?: string; fetcher?: RecordedErrorFetcher } = {},
): Promise<never> {
    const testId = options.testId ?? failedAttemptTestId(readScenarioFailure(config, scenarioKey))
    const summary = await collectSummary(
        config,
        scenarioKey,
        testId,
        testInfo,
        options.fetcher ?? fetchRecordedErrors,
    )

    if (!summary || !(original instanceof Error)) {
        throw original
    }

    const enriched = new Error(`${original.message}\n\n${summary}`)
    const frames = (original.stack ?? '').split('\n').slice(1).join('\n')
    enriched.stack = `${enriched.message}\n${frames}`

    throw enriched
}

async function collectSummary(
    config: ToolkitConfig,
    scenarioKey: string,
    testId: string,
    testInfo: TestInfo,
    fetcher: RecordedErrorFetcher,
): Promise<string | undefined> {
    if (!testId || config.replay) {
        return undefined
    }

    const report = await fetcher(config, testId)
    if (!report || 0 === report.errors.length) {
        return undefined
    }

    await testInfo.attach('typo3-errors.json', {
        body: Buffer.from(JSON.stringify(report, null, 2)),
        contentType: 'application/json',
    })

    // The scenario key is a sanitised absolute path; the spec file reads better.
    const label = testInfo.file ? path.relative(config.paths.consumerRoot, testInfo.file) : scenarioKey

    return formatRecordedErrors(label, report)
}
