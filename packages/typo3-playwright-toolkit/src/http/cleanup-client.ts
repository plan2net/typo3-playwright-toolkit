import type { ToolkitConfig } from '../config.js'
import { SECRET_HEADER, resolveApiSecret } from './api-secret.js'

/** Mirrors the extension's CleanupOutcome. */
export type CleanupOutcome = 'dropped' | 'absent' | 'unclaimed' | 'refused' | 'failed'

export interface CleanupResult {
    testId: string
    outcome: CleanupOutcome
}

export interface SweepReport {
    results: CleanupResult[]
    kept: number
    cutoffMs: number
    /** True when the site could not be reached, which is not a run failure. */
    unreachable: boolean
}

/** The endpoint refuses a larger batch, so requests are chunked to this size. */
export const MAXIMUM_BATCH = 500

const OUTCOMES: readonly CleanupOutcome[] = ['dropped', 'absent', 'unclaimed', 'refused', 'failed']

/**
 * Accepts only these five results. Teardown forgets a database once its result is
 * final, and those records are the only proof the database ever existed — so an
 * answer we do not understand must never count as final.
 */
function parseResults(body: unknown): CleanupResult[] {
    if (!body || typeof body !== 'object' || !Array.isArray((body as { results?: unknown }).results)) {
        return []
    }

    const parsed: CleanupResult[] = []
    for (const entry of (body as { results: unknown[] }).results) {
        if (!entry || typeof entry !== 'object') {
            continue
        }
        const { testId, outcome } = entry as { testId?: unknown; outcome?: unknown }
        if (typeof testId !== 'string') {
            continue
        }
        parsed.push({
            testId,
            outcome: OUTCOMES.includes(outcome as CleanupOutcome)
                ? (outcome as CleanupOutcome)
                : 'failed',
        })
    }

    return parsed
}

export type DropDatabases = (testIds: string[]) => Promise<CleanupResult[]>

export interface CleanupClient {
    drop: DropDatabases
    sweep(keepTestIds: string[], minimumAgeMs: number): Promise<SweepReport>
}

export function httpCleanup(
    config: ToolkitConfig,
    options: { fetchImpl?: typeof fetch; timeoutMs?: number } = {},
): CleanupClient {
    const doFetch = options.fetchImpl ?? fetch
    const timeoutMs = options.timeoutMs ?? (Number(process.env.PW_CLEANUP_TIMEOUT_MS) || 30000)

    async function post(operation: string, payload: Record<string, unknown>): Promise<unknown> {
        const url = `${config.testingURL}/typo3/test-api/databases/${operation}`

        // No test-ID header: one would make the extension provision a database
        // on the way in, and this request exists to remove them.
        const response = await doFetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                [SECRET_HEADER]: resolveApiSecret(config),
            },
            body: JSON.stringify(payload),
            signal: AbortSignal.timeout(timeoutMs),
        })

        if (!response.ok) {
            throw new Error(`${url} answered ${response.status}`)
        }

        return await response.json()
    }

    return {
        async drop(testIds: string[]): Promise<CleanupResult[]> {
            const results: CleanupResult[] = []

            for (let index = 0; index < testIds.length; index += MAXIMUM_BATCH) {
                const batch = testIds.slice(index, index + MAXIMUM_BATCH)
                let reported: CleanupResult[] = []

                try {
                    reported = parseResults(await post('drop', { testIds: batch }))
                } catch (error) {
                    console.error(`[teardown] Cleanup request failed: ${(error as Error).message}`)
                }

                // An id the endpoint said nothing about is not known to be gone, so
                // it counts as failed and keeps the run registry alive.
                const byTestId = new Map(reported.map((result) => [result.testId, result]))
                for (const testId of batch) {
                    results.push(byTestId.get(testId) ?? { testId, outcome: 'failed' })
                }
            }

            return results
        },

        async sweep(keepTestIds: string[], minimumAgeMs: number): Promise<SweepReport> {
            try {
                const body = (await post('sweep', { keepTestIds, minimumAgeMs })) as {
                    kept?: unknown
                    cutoffMs?: unknown
                }

                return {
                    results: parseResults(body),
                    kept: typeof body.kept === 'number' ? body.kept : 0,
                    cutoffMs: typeof body.cutoffMs === 'number' ? body.cutoffMs : minimumAgeMs,
                    unreachable: false,
                }
            } catch (error) {
                console.error(`[teardown] Orphan sweep skipped: ${(error as Error).message}`)

                return { results: [], kept: 0, cutoffMs: minimumAgeMs, unreachable: true }
            }
        },
    }
}
