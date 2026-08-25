import type { ToolkitConfig } from '../config.js'
import type { EnsureStateOutcome } from './ensure-state.js'
import { recordPairFailure } from './pair-state.js'

export function applyPairOutcome<S>(
    outcome: EnsureStateOutcome<S>,
    skip: (reason: string) => void,
): S {
    if (outcome.status === 'ready') {
        return outcome.data
    }

    skip(outcome.reason)

    // Playwright's skip throws; anything else must not fall through into a test
    // that has no state to work with.
    throw new Error(`[typo3-playwright-toolkit] ${outcome.reason}`)
}

/**
 * Recorded so teardown preserves the databases of a pair whose verification
 * failed, the way it does for a failed setup.
 */
export function recordPairVerifyFailure(config: ToolkitConfig, key: string, error: string): void {
    recordPairFailure(config, {
        key: `${key}__verify`,
        pairKey: key,
        triggeringTestId: '',
        attempts: [{ attempt: 1, testId: '', error, durationMs: 0 }],
    })
}
