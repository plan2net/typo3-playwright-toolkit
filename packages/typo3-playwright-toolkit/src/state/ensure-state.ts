import { createHash, randomBytes } from 'node:crypto'
import type { ToolkitConfig } from '../config.js'
import { registerAttempt, recordAttemptOutcome } from './attempt-registry.js'
import {
    commitScenarioState,
    readScenarioState,
    readScenarioFailure,
    recordScenarioFailure,
    type ScenarioAttemptFailure,
} from './scenario-state.js'
import { ensureRunNamespace, runSalt } from './run-namespace.js'
import {
    acquireSetupLock,
    heartbeatSetupLock,
    releaseSetupLock,
    stealSetupLock,
    stillOwns,
} from './setup-lock.js'
import { claimNextAttempt, highestClaimedAttempt } from './attempt-claim.js'

export const SETUP_DEFAULTS = {
    attemptTimeoutMs: 90_000,
    waitTimeoutMs: 300_000,
    attempts: 2,
    lockStaleMs: 15_000,
    pollMs: 100,
}

export interface SetupContext {
    testId: string
    attempt: number
    signal: AbortSignal
}

export interface EnsureStateOptions<S> {
    key: string
    /** What the inspect listing and the backend call this scenario; the key otherwise. */
    name?: string
    /** Identifies the caller, so its own retries rethrow instead of skipping. */
    triggerId: string
    setup: (context: SetupContext) => Promise<S>
    now?: () => number
    sleep?: (ms: number) => Promise<void>
}

export type EnsureStateOutcome<S> =
    | { status: 'ready'; testId: string; attempt: number; data: S; setupRan: boolean; waitedMs: number }
    | { status: 'skip'; reason: string }

/**
 * Same salt, scenario and attempt always give the same ID, so a claimed attempt gets
 * its own database without anyone coordinating; a retry gets a different one.
 * The salt is the run's, not the run ID — see runSalt().
 */
export function deriveTestId(salt: string, key: string, attempt: number): string {
    return createHash('sha256').update(`${salt}:${key}:${attempt}`).digest('hex').slice(0, 16).toUpperCase()
}

function statusError(reason: string): Error {
    return new Error(`[typo3-playwright-toolkit] ${reason}`)
}

/**
 * Records the failure before throwing, even when this worker has none of its own —
 * another one may have burned the attempts. Without a status the remaining tests
 * error instead of skipping with the reason, and teardown drops the databases that
 * would have shown what happened.
 */
function giveUp(
    config: ToolkitConfig,
    key: string,
    triggerId: string,
    failures: ScenarioAttemptFailure[],
    reason: string,
    durationMs: number,
): Error {
    const attempts = failures.length > 0 ? failures : [{ attempt: 0, testId: '', error: reason, durationMs }]
    recordScenarioFailure(config, { key, triggeringTestId: triggerId, attempts })

    return statusError(reason)
}

const TIMED_OUT = Symbol('timed-out')

/**
 * The timeout runs on a real timer, not the injected poll sleep: an abandoned
 * setup keeps running, so only elapsed wall time can tell us to give up on it.
 */
async function runAttempt<S>(
    setup: (context: SetupContext) => Promise<S>,
    context: Omit<SetupContext, 'signal'>,
    timeoutMs: number,
): Promise<{ ok: true; data: S } | { ok: false; error: string }> {
    const controller = new AbortController()
    let timer: NodeJS.Timeout | undefined

    const timeout = new Promise<typeof TIMED_OUT>((resolve) => {
        timer = setTimeout(() => {
            controller.abort()
            resolve(TIMED_OUT)
        }, timeoutMs)
    })

    try {
        const outcome = await Promise.race([setup({ ...context, signal: controller.signal }), timeout])
        if (outcome === TIMED_OUT) {
            return { ok: false, error: `setup did not finish within ${timeoutMs}ms` }
        }

        return { ok: true, data: outcome }
    } catch (error) {
        return { ok: false, error: error instanceof Error ? error.message : String(error) }
    } finally {
        controller.abort()
        clearTimeout(timer)
    }
}

/**
 * Throws instead of skipping when the failure is the caller's own attempt, so a
 * retry sees the error rather than a skip.
 */
function settledOutcome<S>(
    config: ToolkitConfig,
    key: string,
    triggerId: string,
    waitedMs: number,
): EnsureStateOutcome<S> | undefined {
    // State first: a worker that gave up may have written a status while another
    // one went on to succeed, and content that exists beats a report that it does
    // not.
    const committed = readScenarioState<S>(config, key)
    if (committed) {
        return {
            status: 'ready',
            testId: committed.testId,
            attempt: committed.attempt,
            data: committed.data,
            setupRan: false,
            waitedMs,
        }
    }

    const failure = readScenarioFailure(config, key)
    if (!failure) {
        return undefined
    }

    const reason = `setup for "${key}" failed: ${failure.attempts.at(-1)?.error ?? 'unknown error'}`
    if (failure.triggeringTestId === triggerId) {
        throw statusError(reason)
    }

    return { status: 'skip', reason }
}

export async function ensureState<S>(
    config: ToolkitConfig,
    options: EnsureStateOptions<S>,
): Promise<EnsureStateOutcome<S>> {
    const { key, triggerId, setup } = options
    const name = options.name ?? key
    const now = options.now ?? (() => Date.now())
    const sleep = options.sleep ?? ((ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms)))

    // One attempt in replay: a retry would leave the failed folder beside a second one.
    const tuning = { ...SETUP_DEFAULTS, ...config.setup, ...(config.replay ? { attempts: 1 } : {}) }
    const paths = ensureRunNamespace(config)
    const startedAt = now()
    const failures: ScenarioAttemptFailure[] = []

    for (;;) {
        const settled = settledOutcome<S>(config, key, triggerId, now() - startedAt)
        if (settled) {
            return settled
        }

        // No status here: whoever holds the lock may still succeed, and a status
        // would skip every remaining test on a scenario that then works.
        if (now() - startedAt > tuning.waitTimeoutMs) {
            throw statusError(
                `Timed out after ${tuning.waitTimeoutMs}ms waiting for the setup of "${key}". ` +
                    `Another worker may have died holding ${paths.locksDir}/${key}.lock.`,
            )
        }

        // Take the lock before claiming a number: claims are durable, so a
        // waiter that claimed on every poll would burn the attempt budget.
        const nonce = randomBytes(16).toString('hex')
        if (!acquireSetupLock(paths.locksDir, key, { nonce, attempt: highestClaimedAttempt(paths.locksDir, key) + 1 })) {
            stealSetupLock(paths.locksDir, key, nonce, tuning.lockStaleMs, now())
            await sleep(tuning.pollMs)
            continue
        }

        const attempt = claimNextAttempt(paths.locksDir, key)
        if (attempt > tuning.attempts) {
            releaseSetupLock(paths.locksDir, key, nonce)
            const reason =
                `setup for "${key}" gave up after ${tuning.attempts} attempt(s): ` +
                `${failures.at(-1)?.error ?? 'another worker exhausted them'}`

            throw giveUp(config, key, triggerId, failures, reason, now() - startedAt)
        }

        const testId = deriveTestId(runSalt(config), key, attempt)
        registerAttempt(config, { key, name, attempt, testId, nonce })

        const heartbeat = setInterval(() => heartbeatSetupLock(paths.locksDir, key, nonce), 1_000)
        const attemptStartedAt = now()
        let result: Awaited<ReturnType<typeof runAttempt<S>>>
        try {
            result = await runAttempt(setup, { testId, attempt }, tuning.attemptTimeoutMs)
        } finally {
            clearInterval(heartbeat)
        }

        const durationMs = now() - attemptStartedAt
        const fenced = !stillOwns(paths.locksDir, key, nonce)

        if (fenced) {
            recordAttemptOutcome(config, { testId, outcome: 'abandoned', durationMs })
            await sleep(tuning.pollMs)
            continue
        }

        if (result.ok) {
            commitScenarioState(config, { key, testId, attempt, setupMs: durationMs, data: result.data })
            recordAttemptOutcome(config, { testId, outcome: 'committed', durationMs })
            releaseSetupLock(paths.locksDir, key, nonce)

            return {
                status: 'ready',
                testId,
                attempt,
                data: result.data,
                setupRan: true,
                waitedMs: 0,
            }
        }

        failures.push({ attempt, testId, error: result.error, durationMs })
        recordAttemptOutcome(config, { testId, outcome: 'failed', durationMs })
        releaseSetupLock(paths.locksDir, key, nonce)

        if (attempt >= tuning.attempts) {
            recordScenarioFailure(config, { key, triggeringTestId: triggerId, attempts: failures })
            throw statusError(`setup for "${key}" failed: ${result.error}`)
        }
    }
}
