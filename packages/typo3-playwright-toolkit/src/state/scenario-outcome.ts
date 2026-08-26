import type { ToolkitConfig } from '../config.js'
import type { EnsureStateOutcome } from './ensure-state.js'
import { recordScenarioFailure } from './scenario-state.js'

export function applyScenarioOutcome<S>(
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
 * Recorded so teardown preserves the databases of a scenario whose tests
 * failed, the way it does for a failed setup.
 */
export function recordTestFailure(config: ToolkitConfig, key: string, error: string): void {
    recordScenarioFailure(config, {
        key: `${key}__test`,
        scenarioKey: key,
        triggeringTestId: '',
        attempts: [{ attempt: 1, testId: '', error, durationMs: 0 }],
    })
}
