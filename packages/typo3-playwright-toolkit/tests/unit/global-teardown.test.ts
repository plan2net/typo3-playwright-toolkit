import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import {
    runTeardown,
    describePreservedRun,
    sweepOrphans,
    failedScenarioKeys,
    preservedTestIds,
    resolvePreservePlan,
} from '#src/global-teardown.js'
import type { ToolkitConfig } from '#src/config.js'
import type { CleanupClient, CleanupOutcome, CleanupResult, SweepReport } from '#src/http/cleanup-client.js'
import { readAttempts, registerAttempt } from '#src/state/attempt-registry.js'
import { recordScenarioFailure } from '#src/state/scenario-state.js'
import { ensureRunNamespace, runPaths } from '#src/state/run-namespace.js'
import { configForRun } from '../helpers.js'

let tmpRoot: string

interface FakeCleanup extends CleanupClient {
    dropped: string[][]
    swept: { keepTestIds: string[]; minimumAgeMs: number }[]
}

/**
 * Stands in for the endpoint. `outcomes` maps a test ID to what the extension
 * would answer; anything unnamed comes back "dropped".
 */
function fakeCleanup(outcomes: Record<string, CleanupOutcome> = {}): FakeCleanup {
    const dropped: string[][] = []
    const swept: { keepTestIds: string[]; minimumAgeMs: number }[] = []

    return {
        dropped,
        swept,
        async drop(testIds: string[]): Promise<CleanupResult[]> {
            dropped.push(testIds)

            return testIds.map((testId) => ({ testId, outcome: outcomes[testId] ?? 'dropped' }))
        },
        async sweep(keepTestIds: string[], minimumAgeMs: number): Promise<SweepReport> {
            swept.push({ keepTestIds, minimumAgeMs })

            return { results: [], kept: 0, cutoffMs: minimumAgeMs, unreachable: false }
        },
    }
}

beforeEach(() => {
    tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-teardown-'))
})

afterEach(() => {
    fs.rmSync(tmpRoot, { recursive: true, force: true })
    vi.restoreAllMocks()
})

describe('runTeardown', () => {
    it('removes this run and leaves other runs alone', async () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        const otherRun = path.join(config.paths.stateDir, 'runs', 'bbbbbbbbbbbbbbbb')
        fs.mkdirSync(otherRun, { recursive: true })

        await runTeardown(config, { cleanup: fakeCleanup(), preserve: { mode: 'none' } as const })

        expect(fs.existsSync(runPaths(config).runDir)).toBe(false)
        expect(fs.existsSync(otherRun)).toBe(true)
    })

    describe('in replay mode', () => {
        function replayConfig(): ToolkitConfig {
            return { ...configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa'), replay: true }
        }

        it('drops nothing and sweeps nothing', async () => {
            const config = replayConfig()
            ensureRunNamespace(config)
            registerAttempt(config, { key: 'news', attempt: 1, testId: 'ABCD1234EFGH5678', nonce: 'n' })
            const cleanup = fakeCleanup()

            await runTeardown(config, { cleanup, preserve: { mode: 'none' } as const })

            expect(cleanup.dropped).toEqual([])
            expect(cleanup.swept).toEqual([])
        })

        it('removes its own run directory', async () => {
            const config = replayConfig()
            ensureRunNamespace(config)

            await runTeardown(config, { cleanup: fakeCleanup(), preserve: { mode: 'none' } as const })

            expect(fs.existsSync(runPaths(config).runDir)).toBe(false)
        })

        it('reports the login link for the replayed database', async () => {
            const config = replayConfig()
            ensureRunNamespace(config)

            const summary = await runTeardown(config, {
                cleanup: fakeCleanup(),
                preserve: { mode: 'none' } as const,
                secret: 'shh',
            })

            expect(summary.replayUrl).toContain('/typo3/test-api/inspect?replay=1&t=')
        })

        it('reports no leak, so a replay never fails the run', async () => {
            const config = replayConfig()
            ensureRunNamespace(config)
            registerAttempt(config, { key: 'news', attempt: 1, testId: 'ABCD1234EFGH5678', nonce: 'n' })

            const summary = await runTeardown(config, {
                cleanup: fakeCleanup({ ABCD1234EFGH5678: 'failed' }),
                preserve: { mode: 'none' } as const,
            })

            expect(summary).toMatchObject({ dropped: 0, leaked: [] })
        })
    })

    it('keeps the run directory when preserve is true', async () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        ensureRunNamespace(config)

        await runTeardown(config, { cleanup: fakeCleanup(), preserve: { mode: 'all', reason: 'test' } as const })

        expect(fs.existsSync(runPaths(config).runDir)).toBe(true)
    })

    it('does not throw when stateDir is already absent on a second call', async () => {
        const config = configForRun(tmpRoot)
        const options = { cleanup: fakeCleanup(), preserve: { mode: 'none' } as const }

        await runTeardown(config, options)

        await expect(runTeardown(config, options)).resolves.toMatchObject({ leaked: [] })
    })

    // The toolkit no longer knows an engine, a host or a credential: it names the
    // test ids and the extension does the rest. A module that cannot reach
    // child_process cannot shell out to psql or mysql — asserted on the source
    // because ESM exports cannot be spied on.
    it('needs no database client binary anywhere in the package', () => {
        const srcDir = path.dirname(new URL(import.meta.url).pathname)
        const offenders: string[] = []

        const walk = (dir: string): void => {
            for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
                const full = path.join(dir, entry.name)
                if (entry.isDirectory()) {
                    walk(full)
                    continue
                }
                if (!entry.name.endsWith('.ts') || entry.name.endsWith('.test.ts')) {
                    continue
                }
                if (/child_process|\bpsql\b|\bmysqld?\b/.test(fs.readFileSync(full, 'utf8'))) {
                    offenders.push(path.relative(srcDir, full))
                }
            }
        }
        walk(srcDir)

        expect(offenders).toEqual([])
    })
})

describe('runTeardown — only this run', () => {
    it('drops the databases this run recorded', async () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'k', attempt: 1, testId: 'ABCD1234EFGH5678', nonce: 'n' })
        const cleanup = fakeCleanup()

        await runTeardown(config, { cleanup, preserve: { mode: 'none' } as const })

        expect(cleanup.dropped[0]).toEqual(['ABCD1234EFGH5678'])
    })

    it('leaves another run alone', async () => {
        const mine = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        const theirs = configForRun(tmpRoot, 'bbbbbbbbbbbbbbbb')
        registerAttempt(mine, { key: 'k', attempt: 1, testId: 'AAAA1111AAAA1111', nonce: 'n' })
        registerAttempt(theirs, { key: 'k', attempt: 1, testId: 'BBBB2222BBBB2222', nonce: 'n' })
        const cleanup = fakeCleanup()

        await runTeardown(mine, { cleanup, preserve: { mode: 'none' } as const })

        expect(cleanup.dropped.flat()).not.toContain('BBBB2222BBBB2222')
        expect(fs.existsSync(runPaths(theirs).attemptsFile)).toBe(true)
    })

    it('removes its own run directory but not another run', async () => {
        const mine = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        const theirs = configForRun(tmpRoot, 'bbbbbbbbbbbbbbbb')
        registerAttempt(mine, { key: 'k', attempt: 1, testId: 'AAAA1111AAAA1111', nonce: 'n' })
        registerAttempt(theirs, { key: 'k', attempt: 1, testId: 'BBBB2222BBBB2222', nonce: 'n' })

        await runTeardown(mine, { cleanup: fakeCleanup(), preserve: { mode: 'none' } as const })

        expect(fs.existsSync(runPaths(mine).runDir)).toBe(false)
        expect(fs.existsSync(runPaths(theirs).runDir)).toBe(true)
    })

    it('drops nothing and keeps everything when preserving', async () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'k', attempt: 1, testId: 'ABCD1234EFGH5678', nonce: 'n' })
        const cleanup = fakeCleanup()

        await runTeardown(config, { cleanup, preserve: { mode: 'all', reason: 'test' } as const })

        expect(cleanup.dropped).toEqual([])
        expect(cleanup.swept).toEqual([])
        expect(fs.existsSync(runPaths(config).attemptsFile)).toBe(true)
    })

    // Only a failure can change on a retry, so only a failure keeps the registry.
    it('keeps the registry when a drop fails, so the leak stays visible', async () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'k', attempt: 1, testId: 'ABCD1234EFGH5678', nonce: 'n' })

        await runTeardown(config, {
            cleanup: fakeCleanup({ ABCD1234EFGH5678: 'failed' }),
            preserve: { mode: 'none' } as const,
                    })

        expect(fs.existsSync(runPaths(config).attemptsFile)).toBe(true)
        expect(readAttempts(config).map((attempt) => attempt.testId)).toContain('ABCD1234EFGH5678')
    })

    it.each<CleanupOutcome>(['absent', 'unclaimed', 'refused'])(
        'discards the registry for the terminal outcome %s',
        async (outcome) => {
            const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
            registerAttempt(config, { key: 'k', attempt: 1, testId: 'ABCD1234EFGH5678', nonce: 'n' })

            await runTeardown(config, {
                cleanup: fakeCleanup({ ABCD1234EFGH5678: outcome }),
                preserve: { mode: 'none' } as const,
                            })

            expect(fs.existsSync(runPaths(config).runDir)).toBe(false)
        },
    )
})

describe('sweepOrphans', () => {
    const dayMs = 86_400_000

    /**
     * runLastActiveMs takes the newest of the directory, liveness and
     * attempts.jsonl, so all three have to be aged — and the directory last,
     * because writing inside it bumps its own mtime.
     */
    function ageRun(config: ToolkitConfig, ageMs: number): string {
        const paths = runPaths(config)
        const when = new Date(Date.now() - ageMs)
        for (const file of [path.join(paths.runDir, 'liveness'), paths.attemptsFile, paths.runDir]) {
            if (fs.existsSync(file)) {
                fs.utimesSync(file, when, when)
            }
        }

        return paths.runDir
    }

    // Live runs are protected by name; abandoned runs are drained instead, or
    // their databases would be immortal.
    it('keeps only live run ids and sweeps with the configured age', async () => {
        const mine = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(mine, { key: 'k', attempt: 1, testId: 'LIVE1111LIVE1111', nonce: 'n' })
        const cleanup = fakeCleanup()

        await sweepOrphans(mine, cleanup)

        expect(cleanup.swept).toHaveLength(1)
        expect(cleanup.swept[0].keepTestIds).toEqual(['LIVE1111LIVE1111'])
        expect(cleanup.swept[0].minimumAgeMs).toBe(dayMs)
    })

    it('drains an abandoned run and removes its directory', async () => {
        const mine = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        const theirs = configForRun(tmpRoot, 'bbbbbbbbbbbbbbbb')
        registerAttempt(mine, { key: 'k', attempt: 1, testId: 'LIVE1111LIVE1111', nonce: 'n' })
        registerAttempt(theirs, { key: 'k', attempt: 1, testId: 'OLDOLD11OLDOLD11', nonce: 'n' })
        const abandonedDir = ageRun(theirs, 2 * dayMs)
        const cleanup = fakeCleanup()

        const reclaimed = await sweepOrphans(mine, cleanup)

        expect(cleanup.dropped).toEqual([['OLDOLD11OLDOLD11']])
        expect(fs.existsSync(abandonedDir)).toBe(false)
        expect(reclaimed).toBe(1)
        // Never passed as "keep": that is what made revision 1 protect them forever.
        expect(cleanup.swept[0].keepTestIds).toEqual(['LIVE1111LIVE1111'])
    })

    it('keeps an abandoned run directory when one of its drops failed', async () => {
        const mine = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        const theirs = configForRun(tmpRoot, 'bbbbbbbbbbbbbbbb')
        registerAttempt(theirs, { key: 'k', attempt: 1, testId: 'OLDOLD11OLDOLD11', nonce: 'n' })
        const abandonedDir = ageRun(theirs, 2 * dayMs)

        await sweepOrphans(mine, fakeCleanup({ OLDOLD11OLDOLD11: 'failed' }))

        expect(fs.existsSync(abandonedDir)).toBe(true)
    })

    // Classified as abandoned in the first pass, awake by the time we look again:
    // its ids were left out of the keep set, so without re-adding them the sweep
    // would reclaim the databases it has just started using.
    //
    // The window is real rather than mocked: draining the first abandoned run is
    // an await, and the second run wakes during it.
    it('protects a run that woke up between the two liveness checks', async () => {
        const mine = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        const drained = configForRun(tmpRoot, 'bbbbbbbbbbbbbbbb')
        const waking = configForRun(tmpRoot, 'cccccccccccccccc')
        registerAttempt(drained, { key: 'k', attempt: 1, testId: 'GONE1111GONE1111', nonce: 'n' })
        registerAttempt(waking, { key: 'k', attempt: 1, testId: 'WOKE1111WOKE1111', nonce: 'n' })
        ageRun(drained, 2 * dayMs)
        const wakingDir = ageRun(waking, 2 * dayMs)

        const base = fakeCleanup()
        const cleanup: FakeCleanup = {
            ...base,
            async drop(testIds: string[]) {
                // The waking run touches its own liveness while we are busy.
                const now = new Date()
                fs.utimesSync(path.join(wakingDir, 'liveness'), now, now)

                return base.drop(testIds)
            },
        }

        await sweepOrphans(mine, cleanup)

        expect(cleanup.dropped).toEqual([['GONE1111GONE1111']])
        expect(fs.existsSync(wakingDir)).toBe(true)
        expect(cleanup.swept[0].keepTestIds).toContain('WOKE1111WOKE1111')
    })

    it('leaves a recently active run alone', async () => {
        const mine = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        const theirs = configForRun(tmpRoot, 'bbbbbbbbbbbbbbbb')
        registerAttempt(theirs, { key: 'k', attempt: 1, testId: 'RECENT11RECENT11', nonce: 'n' })
        const recentDir = runPaths(theirs).runDir
        const cleanup = fakeCleanup()

        await sweepOrphans(mine, cleanup)

        expect(cleanup.dropped).toEqual([])
        expect(fs.existsSync(recentDir)).toBe(true)
        expect(cleanup.swept[0].keepTestIds).toContain('RECENT11RECENT11')
    })

    it('never reclaims the current run, however old it looks', async () => {
        const mine = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(mine, { key: 'k', attempt: 1, testId: 'MINE1111MINE1111', nonce: 'n' })
        const mineDir = ageRun(mine, 5 * dayMs)
        const cleanup = fakeCleanup()

        await sweepOrphans(mine, cleanup)

        expect(cleanup.dropped).toEqual([])
        expect(fs.existsSync(mineDir)).toBe(true)
        expect(cleanup.swept[0].keepTestIds).toContain('MINE1111MINE1111')
    })

    it('honours a configured orphan age', async () => {
        const mine = { ...configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa'), cleanup: { orphanAgeMs: 1000 } }
        const cleanup = fakeCleanup()

        await sweepOrphans(mine, cleanup)

        expect(cleanup.swept[0].minimumAgeMs).toBe(1000)
    })

    it('is skipped by runTeardown when preserving', async () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        const cleanup = fakeCleanup()

        await runTeardown(config, { cleanup, preserve: { mode: 'all', reason: 'test' } as const })

        expect(cleanup.swept).toEqual([])
    })
})

function failScenario(config: ToolkitConfig, key: string, testId = 'ABCD1234EFGH5678'): void {
    recordScenarioFailure(config, {
        key,
        triggeringTestId: testId,
        attempts: [{ attempt: 1, testId, error: 'boom', durationMs: 1 }],
    })
}

describe('describePreservedRun', () => {
    // The test IDs alone do not say which file they belong to, and a browser
    // cannot send the header, so the line has to carry a clickable link.
    it('names the scenario and gives a link that opens it', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'checkout', attempt: 1, testId: 'AAAAAAAAAAAAAAA1', nonce: 'n' })

        const described = describePreservedRun(config, ['AAAAAAAAAAAAAAA1'], 'the-secret', 0)

        expect(described).toContain('checkout')
        expect(described).toContain('dbAAAAAAAAAAAAAAA1')
        expect(described).toContain('https://example-testing.test/typo3/test-api/inspect?id=AAAAAAAAAAAAAAA1&t=')
    })

    it('leaves the link out when no secret is available', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'checkout', attempt: 1, testId: 'AAAAAAAAAAAAAAA1', nonce: 'n' })

        const described = describePreservedRun(config, ['AAAAAAAAAAAAAAA1'], '', 0)

        expect(described).toContain('dbAAAAAAAAAAAAAAA1')
        expect(described).not.toContain('test-api/inspect')
    })
})

describe('failedScenarioKeys', () => {
    it('is empty when nothing failed', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')

        expect(failedScenarioKeys(config)).toEqual([])
    })

    it('names the scenario that recorded a failure', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        failScenario(config, 'demo')

        expect(failedScenarioKeys(config)).toEqual(['demo'])
    })

    it('reads the scenario key out of the record, not the file name', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        recordScenarioFailure(config, {
            key: 'demo__test',
            scenarioKey: 'demo',
            triggeringTestId: '',
            attempts: [{ attempt: 1, testId: '', error: 'assertion', durationMs: 0 }],
        })

        expect(failedScenarioKeys(config)).toEqual(['demo'])
    })

    // Deciding "nothing failed" from a record we cannot read throws the evidence away.
    it('treats an unreadable record as a failure of every scenario', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        ensureRunNamespace(config)
        fs.writeFileSync(path.join(runPaths(config).failuresDir, 'broken.json'), '{not json')

        expect(failedScenarioKeys(config)).toEqual(['*'])
    })
})

describe('preservedTestIds', () => {
    it('keeps every database of a failed scenario, including earlier attempts', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'broken', attempt: 1, testId: 'AAAAAAAAAAAAAAA1', nonce: 'n' })
        registerAttempt(config, { key: 'broken', attempt: 2, testId: 'AAAAAAAAAAAAAAA2', nonce: 'n' })
        registerAttempt(config, { key: 'fine', attempt: 1, testId: 'BBBBBBBBBBBBBBB1', nonce: 'n' })
        failScenario(config, 'broken')

        expect(preservedTestIds(config).sort()).toEqual(['AAAAAAAAAAAAAAA1', 'AAAAAAAAAAAAAAA2'])
    })

    it('keeps nothing when every scenario passed', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'fine', attempt: 1, testId: 'BBBBBBBBBBBBBBB1', nonce: 'n' })

        expect(preservedTestIds(config)).toEqual([])
    })

    it('keeps every database when a record could not be read', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'fine', attempt: 1, testId: 'BBBBBBBBBBBBBBB1', nonce: 'n' })
        fs.writeFileSync(path.join(runPaths(config).failuresDir, 'broken.json'), '{not json')

        expect(preservedTestIds(config)).toEqual(['BBBBBBBBBBBBBBB1'])
    })
})

describe('resolvePreservePlan', () => {
    it('keeps only the failed scenario by default', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'broken', attempt: 1, testId: 'AAAAAAAAAAAAAAA1', nonce: 'n' })
        registerAttempt(config, { key: 'fine', attempt: 1, testId: 'BBBBBBBBBBBBBBB1', nonce: 'n' })
        failScenario(config, 'broken')

        expect(resolvePreservePlan(config, false)).toEqual({
            mode: 'some',
            testIds: ['AAAAAAAAAAAAAAA1'],
            reason: '1 failed scenario',
        })
    })

    it('keeps nothing when a run had no failures', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')

        expect(resolvePreservePlan(config, false)).toEqual({ mode: 'none' })
    })

    // NO_DATABASE_CLEANUP is "touch nothing", so it stays all or nothing.
    it('keeps everything when cleanup was switched off', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')

        expect(resolvePreservePlan(config, true)).toEqual({
            mode: 'all',
            reason: 'NO_DATABASE_CLEANUP=1',
        })
    })

    it('keeps everything on failure when the consumer asked for that', () => {
        const config = { ...configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa'), cleanup: { preserveOnFailure: 'all' as const } }
        failScenario(config, 'broken')

        expect(resolvePreservePlan(config, false)).toEqual({
            mode: 'all',
            reason: '1 failed scenario',
        })
    })

    it('keeps nothing on failure when the consumer switched preserving off', () => {
        const config = { ...configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa'), cleanup: { preserveOnFailure: 'none' as const } }
        failScenario(config, 'broken')

        expect(resolvePreservePlan(config, false)).toEqual({ mode: 'none' })
    })
})

describe('runTeardown — preserving only what failed', () => {
    it('drops the passing databases and keeps the failed one', async () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'broken', attempt: 1, testId: 'AAAAAAAAAAAAAAA1', nonce: 'n' })
        registerAttempt(config, { key: 'fine', attempt: 1, testId: 'BBBBBBBBBBBBBBB1', nonce: 'n' })
        const cleanup = fakeCleanup()

        const summary = await runTeardown(config, {
            cleanup,
            preserve: { mode: 'some', testIds: ['AAAAAAAAAAAAAAA1'] },
        })

        expect(cleanup.dropped.flat()).toEqual(['BBBBBBBBBBBBBBB1'])
        expect(summary.preserved).toEqual(['AAAAAAAAAAAAAAA1'])
    })

    it('protects the kept database from its own sweep', async () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'broken', attempt: 1, testId: 'AAAAAAAAAAAAAAA1', nonce: 'n' })
        const cleanup = fakeCleanup()

        await runTeardown(config, { cleanup, preserve: { mode: 'some', testIds: ['AAAAAAAAAAAAAAA1'] } })

        expect(cleanup.swept[0]?.keepTestIds).toContain('AAAAAAAAAAAAAAA1')
    })

    it('keeps the run directory when it kept a database', async () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'broken', attempt: 1, testId: 'AAAAAAAAAAAAAAA1', nonce: 'n' })

        await runTeardown(config, {
            cleanup: fakeCleanup(),
            preserve: { mode: 'some', testIds: ['AAAAAAAAAAAAAAA1'] },
        })

        expect(fs.existsSync(runPaths(config).runDir)).toBe(true)
    })
})

describe('runTeardown — reporting leaks', () => {
    // A green run that leaked databases is how a CI job quietly fills a disk.
    it('reports the databases it could not drop', async () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'k', attempt: 1, testId: 'ABCD1234EFGH5678', nonce: 'n' })

        const summary = await runTeardown(config, {
            cleanup: fakeCleanup({ ABCD1234EFGH5678: 'failed' }),
            preserve: { mode: 'none' } as const,
                    })

        expect(summary.leaked).toEqual(['ABCD1234EFGH5678'])
    })

    it('reports no leak when everything was dropped', async () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'k', attempt: 1, testId: 'ABCD1234EFGH5678', nonce: 'n' })

        const summary = await runTeardown(config, {
            cleanup: fakeCleanup(),
            preserve: { mode: 'none' } as const,
                    })

        expect(summary.leaked).toEqual([])
        expect(summary.dropped).toBe(1)
    })

    it('counts a refused database as a leak too', async () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'k', attempt: 1, testId: 'ABCD1234EFGH5678', nonce: 'n' })

        const summary = await runTeardown(config, {
            cleanup: fakeCleanup({ ABCD1234EFGH5678: 'refused' }),
            preserve: { mode: 'none' } as const,
                    })

        expect(summary.leaked).toEqual(['ABCD1234EFGH5678'])
    })

    it('reports nothing leaked when preserving on purpose', async () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'k', attempt: 1, testId: 'ABCD1234EFGH5678', nonce: 'n' })

        const summary = await runTeardown(config, {
            cleanup: fakeCleanup(),
            preserve: { mode: 'all', reason: 'test' } as const,
                    })

        expect(summary.leaked).toEqual([])
        expect(summary.preserved).toEqual(['ABCD1234EFGH5678'])
    })
})

describe('describePreservedRun', () => {
    it('names the run, its scenarios and its databases', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'demo', attempt: 1, testId: 'ABCD1234EFGH5678', nonce: 'n' })

        const description = describePreservedRun(config)

        expect(description).toContain('aaaaaaaaaaaaaaaa')
        expect(description).toContain('demo')
        expect(description).toContain('dbABCD1234EFGH5678')
    })
})
