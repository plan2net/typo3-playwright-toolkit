import type { ToolkitConfig } from '../config.js'
import { SECRET_HEADER, resolveApiSecret } from '../http/api-secret.js'

export type SetupCacheOutcome = 'applied' | 'absent' | 'refused' | 'stored'

export interface RestoredSetup {
    outcome: SetupCacheOutcome
    state: Record<string, unknown>
    /** Why a refusal happened, in the extension's words. */
    detail: string
}

export interface SetupCacheClient {
    restore(testId: string, key: string): Promise<RestoredSetup>
    store(
        testId: string,
        key: string,
        state: Record<string, unknown>,
        onlyIfPresent?: boolean,
    ): Promise<SetupCacheOutcome>
}

export function httpSetupCache(
    config: ToolkitConfig,
    options: { fetchImpl?: typeof fetch; timeoutMs?: number } = {},
): SetupCacheClient {
    const doFetch = options.fetchImpl ?? fetch
    const timeoutMs = options.timeoutMs ?? (Number(process.env.PW_CLEANUP_TIMEOUT_MS) || 30000)

    async function post(operation: string, payload: Record<string, unknown>): Promise<Record<string, unknown>> {
        const url = `${config.testingURL}/typo3/test-api/setup-cache/${operation}`

        const response = await doFetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                [SECRET_HEADER]: resolveApiSecret(config),
            },
            body: JSON.stringify(payload),
            signal: AbortSignal.timeout(timeoutMs),
            redirect: 'manual',
        })

        if (!response.ok) {
            throw new Error(`${url} answered ${response.status}`)
        }

        return (await response.json()) as Record<string, unknown>
    }

    return {
        async restore(testId: string, key: string): Promise<RestoredSetup> {
            const answer = await post('restore', { testId, key })

            return {
                outcome: answer.outcome as SetupCacheOutcome,
                state: (answer.state ?? {}) as Record<string, unknown>,
                detail: 'string' === typeof answer.detail ? answer.detail : '',
            }
        },

        async store(
            testId: string,
            key: string,
            state: Record<string, unknown>,
            onlyIfPresent = false,
        ): Promise<SetupCacheOutcome> {
            return (await post('store', { testId, key, state, onlyIfPresent })).outcome as SetupCacheOutcome
        },
    }
}
