import type { ToolkitConfig } from '../config.js'
import { SECRET_HEADER, resolveApiSecret } from './api-secret.js'

export interface RecordedError {
    uid: number
    source: string
    at: string
    message: string
    count: number
    level?: string
    component?: string
    table?: string
    recordUid?: number
    class?: string
    code?: number
    file?: string
    line?: number
}

export interface RecordedErrorReport {
    testId: string
    truncated: boolean
    errors: RecordedError[]
}

/** The wire keeps TYPO3's own spelling; only the report shows the proper names. */
const SOURCE_NAMES: Record<string, string> = {
    php: 'PHP',
    datahandler: 'DataHandler',
}

export function formatRecordedErrors(scenarioKey: string, report: RecordedErrorReport): string {
    const entries = report.errors.map((error, index) => {
        const source = SOURCE_NAMES[error.source] ?? error.source
        const origin = [source, error.level, error.table].filter(Boolean).join(' ')
        const repeats = error.count > 1 ? ` (${error.count}x)` : ''

        return `  ${index + 1}. ${origin}${repeats}\n     ${error.message}`
    })

    return (
        `[typo3-playwright-toolkit] TYPO3 recorded ${report.errors.length} ` +
        `${1 === report.errors.length ? 'error' : 'errors'} for scenario "${scenarioKey}" ` +
        `(test ${report.testId}):\n\n${entries.join('\n\n')}`
    )
}

export async function fetchRecordedErrors(
    config: ToolkitConfig,
    testId: string,
    options: { fetchImpl?: typeof fetch; timeoutMs?: number; secret?: string } = {},
): Promise<RecordedErrorReport | undefined> {
    const doFetch = options.fetchImpl ?? fetch

    // The test has already failed; a problem here must not add a second one.
    try {
        // No test-ID header: it would provision the database on the way in, so
        // asking about a test that never ran would create it.
        const response = await doFetch(
            `${config.testingURL}/typo3/test-api/errors?id=${encodeURIComponent(testId)}`,
            {
                method: 'GET',
                headers: { [SECRET_HEADER]: options.secret ?? resolveApiSecret(config) },
                signal: AbortSignal.timeout(options.timeoutMs ?? 5000),
            },
        )

        if (!response.ok) {
            return undefined
        }

        const body = (await response.json()) as Partial<RecordedErrorReport>

        return {
            testId: body.testId ?? testId,
            truncated: body.truncated ?? false,
            errors: Array.isArray(body.errors) ? body.errors : [],
        }
    } catch {
        return undefined
    }
}
