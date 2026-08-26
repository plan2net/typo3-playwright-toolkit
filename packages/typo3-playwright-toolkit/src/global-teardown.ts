import * as fs from 'fs'
import * as path from 'path'
import { getToolkitConfig, type ToolkitConfig } from './config.js'
import { httpCleanup, type CleanupClient } from './http/cleanup-client.js'
import { readAttemptsFrom, readRegisteredTestIds } from './state/attempt-registry.js'
import { listRunIds, runLastActiveMs, runPaths, runsRoot } from './state/run-namespace.js'
import { assertDeletableDirectory, safeJoin } from './state/safe-paths.js'
import { inspectUrl } from './inspect/token.js'
import { REPLAY_TEST_ID } from './contract.js'
import { recordReplayTarget } from './inspect/replay-target.js'
import { resolveApiSecret } from './http/api-secret.js'

export const DEFAULT_ORPHAN_AGE_MS = 86_400_000

/** Age at which a TYPO3 session directory is no longer any run's. */
const STALE_SESSION_AGE_MS = 60 * 60 * 1000

const ALL_SCENARIOS = '*'

export function failedScenarioKeys(config: ToolkitConfig): string[] {
    const directory = runPaths(config).failuresDir
    if (!fs.existsSync(directory)) {
        return []
    }

    const keys = new Set<string>()
    for (const entry of fs.readdirSync(directory)) {
        if (!entry.endsWith('.json')) {
            continue
        }
        try {
            const record = JSON.parse(fs.readFileSync(path.join(directory, entry), 'utf-8')) as {
                scenarioKey?: unknown
            }
            keys.add('string' === typeof record.scenarioKey ? record.scenarioKey : ALL_SCENARIOS)
        } catch {
            // The run we cannot read is the one most likely to need its databases.
            console.error(`[teardown] Unreadable failure record ${entry}: keeping every database.`)
            keys.add(ALL_SCENARIOS)
        }
    }

    return [...keys]
}

export function preservedTestIds(config: ToolkitConfig): string[] {
    const failed = new Set(failedScenarioKeys(config))
    if (failed.size === 0) {
        return []
    }

    const attempts = readAttemptsFrom(runPaths(config).attemptsFile)
    const keepAll = failed.has(ALL_SCENARIOS)

    return [
        ...new Set(
            attempts
                .filter((attempt) => keepAll || failed.has(attempt.key))
                .map((attempt) => attempt.testId),
        ),
    ]
}

export type PreservePlan =
    | { mode: 'none' }
    | { mode: 'all'; reason?: string }
    | { mode: 'some'; testIds: string[]; reason?: string }

export function resolvePreservePlan(config: ToolkitConfig, cleanupSwitchedOff: boolean): PreservePlan {
    if (cleanupSwitchedOff) {
        return { mode: 'all', reason: 'NO_DATABASE_CLEANUP=1' }
    }

    const failed = failedScenarioKeys(config)
    if (failed.length === 0) {
        return { mode: 'none' }
    }

    const reason = `${failed.length} failed scenario${failed.length === 1 ? '' : 's'}`

    switch (config.cleanup?.preserveOnFailure ?? 'failed') {
        case 'none':
            return { mode: 'none' }
        case 'all':
            return { mode: 'all', reason }
        default:
            return { mode: 'some', testIds: preservedTestIds(config), reason }
    }
}

function preserveList(plan: PreservePlan): string[] {
    return 'some' === plan.mode ? plan.testIds : []
}

export interface TeardownOptions {
    /** Injectable so tests need no site; see httpCleanup for the real one. */
    cleanup: CleanupClient
    preserve: PreservePlan
    /** Signs the replay login link; resolved from the file when omitted. */
    secret?: string
}

export interface TeardownSummary {
    dropped: number
    /** Test IDs whose database may still exist, so the caller can fail the run. */
    leaked: string[]
    preserved: string[]
    /** Replay only: the backend login link for the replayed database. */
    replayUrl?: string
}

/** Only live runs go in `keepTestIds`; an abandoned run's databases are dropped. */
export async function sweepOrphans(
    config: ToolkitConfig,
    cleanup: CleanupClient,
    now: number = Date.now(),
): Promise<number> {
    const { stateDir } = config.paths
    const orphanAgeMs = config.cleanup?.orphanAgeMs ?? DEFAULT_ORPHAN_AGE_MS
    const cutoff = now - orphanAgeMs
    const currentRunId = runPaths(config).runId
    let reclaimed = 0

    const liveTestIds = new Set<string>()
    const abandonedRunIds: string[] = []

    for (const runId of listRunIds(stateDir)) {
        const runDir = path.join(runsRoot(stateDir), runId)
        const lastActiveMs = runLastActiveMs(runDir)

        if (lastActiveMs === 0) {
            continue
        }
        if (runId !== currentRunId && lastActiveMs < cutoff) {
            abandonedRunIds.push(runId)
            continue
        }
        for (const attempt of readAttemptsFrom(path.join(runDir, 'attempts.jsonl'))) {
            liveTestIds.add(attempt.testId)
        }
    }

    for (const runId of abandonedRunIds) {
        const runDir = path.join(runsRoot(stateDir), runId)

        // Re-check: another sweeper may have taken it, or it may have woken up.
        const stillAbandoned = runLastActiveMs(runDir)
        if (stillAbandoned === 0 || stillAbandoned >= cutoff) {
            // A run that woke up is live now, and its ids were left out of the
            // keep set during the first pass — so without this the sweep below
            // would reclaim the databases it has just started using again.
            for (const attempt of readAttemptsFrom(path.join(runDir, 'attempts.jsonl'))) {
                liveTestIds.add(attempt.testId)
            }
            continue
        }

        const testIds = readAttemptsFrom(path.join(runDir, 'attempts.jsonl')).map(
            (attempt) => attempt.testId,
        )
        const results = await cleanup.drop(testIds)
        reclaimed += results.filter((result) => 'dropped' === result.outcome).length

        // Only a failure is worth retrying, so the directory survives just that.
        if (results.some((result) => 'failed' === result.outcome)) {
            console.error(`[teardown] Keeping abandoned run ${runId}: some databases could not be dropped.`)
            continue
        }
        fs.rmSync(runDir, { recursive: true, force: true })
    }

    const sweep = await cleanup.sweep([...liveTestIds], orphanAgeMs)
    reclaimed += sweep.results.filter((result) => 'dropped' === result.outcome).length

    return reclaimed
}

export function describePreservedRun(
    config: ToolkitConfig,
    keptTestIds?: string[],
    secret = '',
    now: number = Date.now(),
): string {
    const paths = runPaths(config)
    const attempts = readAttemptsFrom(paths.attemptsFile)
    const kept = undefined === keptTestIds ? attempts : attempts.filter((a) => keptTestIds.includes(a.testId))

    if (kept.length === 0) {
        return `[teardown] Run ${paths.runId} preserved (no databases recorded).`
    }

    const lines = kept.map((attempt) => {
        const line = `  ${attempt.name ?? attempt.key} → db${attempt.testId}`
        if ('' === secret) {
            return line
        }

        return `${line}\n    ${inspectUrl(config.testingURL, secret, attempt.testId, now)}`
    })
    const dropped = attempts.length - kept.length
    const alsoDropped = dropped > 0 ? `, ${dropped} passing one(s) dropped` : ''

    return [
        `[teardown] Run ${paths.runId} kept ${kept.length} database(s)${alsoDropped}:`,
        ...lines,
    ].join('\n')
}

export async function runTeardown(config: ToolkitConfig, options: TeardownOptions): Promise<TeardownSummary> {
    const { stateDir, sessionDir } = config.paths
    const { cleanup, preserve } = options
    const secret = options.secret ?? inspectSecret(config)

    // setToolkitConfig bypasses defineToolkitConfig, so re-check here: everything
    // below this line deletes recursively.
    assertDeletableDirectory('stateDir', stateDir, config.paths.consumerRoot)
    assertDeletableDirectory('sessionDir', sessionDir, config.paths.consumerRoot)

    // Nothing to drop, but the state has to go: a pinned run id would otherwise
    // make the next replay skip every setup on this run's committed state.
    if (config.replay) {
        removeRunState(config, false)
        recordReplayTarget(stateDir, config.testingURL)

        return {
            dropped: 0,
            leaked: [],
            preserved: [],
            replayUrl:
                '' === secret ? undefined : inspectUrl(config.testingURL, secret, REPLAY_TEST_ID, Date.now()),
        }
    }

    const kept = 'all' === preserve.mode ? readRegisteredTestIds(config) : preserveList(preserve)

    const drop =
        'all' === preserve.mode
            ? { dropped: 0, leaked: [], incomplete: false }
            : await dropRegisteredDatabases(config, cleanup, kept)

    // A partial preserve still sweeps: the kept ids reach keepTestIds through
    // this run's attempts.jsonl.
    if ('all' !== preserve.mode) {
        await sweepOrphans(config, cleanup)
    }

    const cleanedSessions = pruneStaleSessions(sessionDir, Date.now() - STALE_SESSION_AGE_MS)

    if ('none' === preserve.mode) {
        removeRunState(config, drop.incomplete)
        report(drop.dropped, cleanedSessions)
    }

    return { dropped: drop.dropped, leaked: drop.leaked, preserved: kept }
}

interface DropReport {
    dropped: number
    leaked: string[]
    /** A database may still exist, so the run registry has to survive. */
    incomplete: boolean
}

async function dropRegisteredDatabases(
    config: ToolkitConfig,
    cleanup: CleanupClient,
    keep: string[],
): Promise<DropReport> {
    const report: DropReport = { dropped: 0, leaked: [], incomplete: false }
    const kept = new Set(keep)
    const targets = readRegisteredTestIds(config).filter((testId) => !kept.has(testId))

    if (targets.length === 0) {
        return report
    }

    for (const result of await cleanup.drop(targets)) {
        if ('dropped' === result.outcome) {
            report.dropped++
            continue
        }
        // Only a failure can change on a retry, so only it keeps the registry.
        if ('failed' === result.outcome) {
            report.incomplete = true
            report.leaked.push(result.testId)
            continue
        }
        // Terminal but worth saying out loud: something else owns a name this
        // run recorded, or the id was never contract-shaped.
        if ('absent' !== result.outcome) {
            console.error(`[teardown] db${result.testId}: ${result.outcome}`)
            report.leaked.push(result.testId)
        }
    }

    return report
}

function pruneStaleSessions(sessionDir: string, cutoffMs: number): number {
    if (!fs.existsSync(sessionDir)) {
        return 0
    }

    let cleaned = 0
    for (const dir of fs.readdirSync(sessionDir)) {
        const sessionPath = safeJoin(sessionDir, dir)
        // sessionDir is shared, so another teardown may have taken it since the listing.
        const stats = fs.statSync(sessionPath, { throwIfNoEntry: false })
        if (stats?.isDirectory() && stats.mtimeMs < cutoffMs) {
            fs.rmSync(sessionPath, { recursive: true, force: true })
            cleaned++
        }
    }

    return cleaned
}

function removeRunState(config: ToolkitConfig, keepRegistry: boolean): void {
    const paths = runPaths(config)

    // Keep the registry when anything was left behind: it is the only record of
    // the leak. Only this run's directory either way — other runs live in stateDir too.
    if (keepRegistry) {
        console.error(
            `[teardown] Keeping ${paths.runDir}: some databases could not be dropped. ` +
                'Their ids are in attempts.jsonl.',
        )

        return
    }

    fs.rmSync(paths.runDir, { recursive: true, force: true })
}

function report(droppedDatabases: number, cleanedSessions: number): void {
    const parts: string[] = []
    if (droppedDatabases > 0) {
        parts.push(`${droppedDatabases} DBs`)
    }
    if (cleanedSessions > 0) {
        parts.push(`${cleanedSessions} session dirs`)
    }

    if (parts.length > 0) {
        console.log(`[teardown] Cleaned ${parts.join(', ')}.`)
    }
}

async function globalTeardown(): Promise<void> {
    const config = getToolkitConfig()
    const preserve = resolvePreservePlan(config, process.env.NO_DATABASE_CLEANUP === '1')

    let summary: TeardownSummary
    try {
        summary = await runTeardown(config, { cleanup: httpCleanup(config), preserve })

        if (undefined !== summary.replayUrl) {
            console.log(`\n[replay] Everything was replayed into the testing site's own database.`)
            console.log(`[replay] This link logs you into its backend for the next 15 minutes:\n`)
            console.log(`  ${summary.replayUrl}\n`)
        }

        // Replay keeps its one database regardless, so there is nothing to report.
        if ('none' !== preserve.mode && !config.replay) {
            console.log(`[teardown] Kept for debugging (${preserve.reason}).`)
            console.log(
                describePreservedRun(
                    config,
                    'some' === preserve.mode ? preserve.testIds : undefined,
                    inspectSecret(config),
                ),
            )
        }
    } catch (error) {
        console.error('[teardown] Error during cleanup:', error)

        if (failOnLeak(config)) {
            throw error
        }
        return
    }

    if (summary.leaked.length > 0 && failOnLeak(config)) {
        // Swallowing this is how a suite stays green while it fills the disk.
        throw new Error(
            `[teardown] ${summary.leaked.length} test database(s) could not be dropped: ` +
                `${summary.leaked.map((testId) => `db${testId}`).join(', ')}. ` +
                'Set cleanup.failOnLeak to false to downgrade this to a warning.',
        )
    }
}

/** No secret means no link; the databases are still named, which is the important part. */
function inspectSecret(config: ToolkitConfig): string {
    try {
        return resolveApiSecret(config)
    } catch {
        return ''
    }
}

/** Defaults to failing in CI, where a leak has no one watching the output. */
function failOnLeak(config: ToolkitConfig): boolean {
    return config.cleanup?.failOnLeak ?? !!process.env.CI
}

export default globalTeardown
