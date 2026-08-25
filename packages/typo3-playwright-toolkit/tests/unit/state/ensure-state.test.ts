import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import type { ToolkitConfig } from '#src/config.js'
import { configForRun } from '../../helpers.js'
import { deriveTestId, ensureState } from '#src/state/ensure-state.js'
import { TEST_ID_PATTERN } from '#src/contract.js'
import { readAttempts } from '#src/state/attempt-registry.js'
import { commitPairState, readPairState, readPairFailure, recordPairFailure } from '#src/state/pair-state.js'
import { ensureRunNamespace, runPaths, runSalt } from '#src/state/run-namespace.js'
import { acquireSetupLock, readLockOwner, releaseSetupLock, stealSetupLock } from '#src/state/setup-lock.js'
import { claimNextAttempt, highestClaimedAttempt } from '#src/state/attempt-claim.js'

let tmpRoot: string
let config: ToolkitConfig

const noWait = { sleep: async () => {} }

beforeEach(() => {
    tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-ensure-'))
    config = {
        ...configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa'),
        setup: { pollMs: 1, waitTimeoutMs: 1_000, attemptTimeoutMs: 1_000 },
    }
    ensureRunNamespace(config)
})

afterEach(() => {
    fs.rmSync(tmpRoot, { recursive: true, force: true })
})

describe('deriveTestId', () => {
    it('matches the wire contract', () => {
        expect(deriveTestId('run', 'pair', 1)).toMatch(TEST_ID_PATTERN)
    })

    it('is the same for the same run, pair and attempt', () => {
        expect(deriveTestId('run', 'pair', 1)).toBe(deriveTestId('run', 'pair', 1))
    })

    it('differs per attempt, so a retry gets a fresh database', () => {
        expect(deriveTestId('run', 'pair', 1)).not.toBe(deriveTestId('run', 'pair', 2))
    })

    it('differs per pair and per run', () => {
        expect(deriveTestId('run', 'one', 1)).not.toBe(deriveTestId('run', 'two', 1))
        expect(deriveTestId('run-a', 'pair', 1)).not.toBe(deriveTestId('run-b', 'pair', 1))
    })
})

describe('ensureState', () => {
    it('runs the setup and returns its data', async () => {
        const outcome = await ensureState(config, {
            key: 'pair',
            triggerId: 't1',
            setup: async () => ({ slug: '/accordion' }),
            ...noWait,
        })

        expect(outcome).toMatchObject({ status: 'ready', setupRan: true })
        expect(outcome.status === 'ready' && outcome.data).toEqual({ slug: '/accordion' })
    })

    it('runs the setup once for repeated calls', async () => {
        let runs = 0
        const options = {
            key: 'pair',
            triggerId: 't1',
            setup: async () => {
                runs++

                return {}
            },
            ...noWait,
        }

        await ensureState(config, options)
        const second = await ensureState(config, { ...options, triggerId: 't2' })

        expect(runs).toBe(1)
        expect(second).toMatchObject({ status: 'ready', setupRan: false })
    })

    it('returns state another worker already committed', async () => {
        commitPairState(config, { key: 'pair', testId: 'ABCD1234EFGH5678', attempt: 1, setupMs: 1, data: { a: 1 } })

        const outcome = await ensureState(config, {
            key: 'pair',
            triggerId: 't1',
            setup: async () => {
                throw new Error('must not run')
            },
            ...noWait,
        })

        expect(outcome).toMatchObject({ status: 'ready', setupRan: false, testId: 'ABCD1234EFGH5678' })
    })

    it('records each attempt before running the setup', async () => {
        await ensureState(config, { key: 'pair', triggerId: 't1', setup: async () => ({}), ...noWait })

        const [record] = readAttempts(config)
        expect(record).toMatchObject({ key: 'pair', attempt: 1 })
        expect(record.testId).toMatch(TEST_ID_PATTERN)
    })

    it('commits under the test id the winning attempt used', async () => {
        const outcome = await ensureState(config, {
            key: 'pair',
            triggerId: 't1',
            setup: async () => ({}),
            ...noWait,
        })

        expect(outcome.status === 'ready' && outcome.testId).toBe(deriveTestId(runSalt(config), 'pair', 1))
    })

    /**
     * Another worker can burn the whole attempt budget, leaving this one nothing to
     * claim and no failure of its own to report. Without a status the other tests
     * error instead of skipping, and teardown drops the databases that would have
     * shown what happened.
     */
    it('records a status when the attempts were exhausted elsewhere', async () => {
        const locksDir = runPaths(config).locksDir
        claimNextAttempt(locksDir, 'pair')
        claimNextAttempt(locksDir, 'pair')

        await expect(
            ensureState(config, { key: 'pair', triggerId: 't1', setup: async () => ({}), ...noWait }),
        ).rejects.toThrow(/gave up/)

        expect(readPairFailure(config, 'pair')?.pairKey).toBe('pair')
    })

    it('lets the next test skip with the reason instead of erroring', async () => {
        const locksDir = runPaths(config).locksDir
        claimNextAttempt(locksDir, 'pair')
        claimNextAttempt(locksDir, 'pair')
        await ensureState(config, { key: 'pair', triggerId: 't1', setup: async () => ({}), ...noWait }).catch(
            () => undefined,
        )

        const outcome = await ensureState(config, {
            key: 'pair',
            triggerId: 't2',
            setup: async () => ({}),
            ...noWait,
        })

        expect(outcome.status).toBe('skip')
    })

    // A test ID alone points a request at that test's database, so a pinned
    // PW_RUN_ID must not be enough to work them out.
    it('derives an id the run id alone does not give away', async () => {
        const outcome = await ensureState(config, {
            key: 'pair',
            triggerId: 't1',
            setup: async () => ({}),
            ...noWait,
        })

        expect(outcome.status === 'ready' && outcome.testId).not.toBe(
            deriveTestId('aaaaaaaaaaaaaaaa', 'pair', 1),
        )
    })

    it('frees the lock once it is done', async () => {
        await ensureState(config, { key: 'pair', triggerId: 't1', setup: async () => ({}), ...noWait })

        expect(readLockOwner(runPaths(config).locksDir, 'pair')).toBeUndefined()
    })
})

describe('ensureState — a pair that already failed', () => {
    beforeEach(() => {
        recordPairFailure(config, {
            key: 'pair',
            triggeringTestId: 'trigger',
            attempts: [{ attempt: 1, testId: 'ABCD1234EFGH5678', error: 'content build failed', durationMs: 3 }],
        })
    })

    it('tells a sibling test to skip', async () => {
        const outcome = await ensureState(config, {
            key: 'pair',
            triggerId: 'someone-else',
            setup: async () => ({}),
            ...noWait,
        })

        expect(outcome.status).toBe('skip')
        expect(outcome.status === 'skip' && outcome.reason).toMatch(/content build failed/)
    })

    it('rethrows for the test that triggered the setup', async () => {
        await expect(
            ensureState(config, { key: 'pair', triggerId: 'trigger', setup: async () => ({}), ...noWait }),
        ).rejects.toThrow(/content build failed/)
    })

    it('does not run the setup again', async () => {
        let runs = 0
        await ensureState(config, {
            key: 'pair',
            triggerId: 'someone-else',
            setup: async () => {
                runs++

                return {}
            },
            ...noWait,
        })

        expect(runs).toBe(0)
    })
})

describe('ensureState — retries', () => {
    it('retries with a fresh test id and succeeds', async () => {
        const seen: string[] = []
        const outcome = await ensureState(config, {
            key: 'pair',
            triggerId: 't1',
            setup: async ({ testId }) => {
                seen.push(testId)
                if (seen.length === 1) {
                    throw new Error('first attempt failed')
                }

                return { ok: true }
            },
            ...noWait,
        })

        expect(outcome.status).toBe('ready')
        expect(seen).toHaveLength(2)
        expect(seen[0]).not.toBe(seen[1])
    })

    it('records every attempt in the registry', async () => {
        await ensureState(config, {
            key: 'pair',
            triggerId: 't1',
            setup: async ({ attempt }) => {
                if (attempt === 1) {
                    throw new Error('first attempt failed')
                }

                return {}
            },
            ...noWait,
        })

        expect(readAttempts(config).map((a) => a.attempt)).toEqual([1, 2])
    })

    it('gives up after the configured number of attempts', async () => {
        let runs = 0

        await expect(
            ensureState(config, {
                key: 'pair',
                triggerId: 't1',
                setup: async () => {
                    runs++
                    throw new Error('always fails')
                },
                ...noWait,
            }),
        ).rejects.toThrow(/always fails/)

        expect(runs).toBe(2)
    })

    it('writes a terminal status naming the trigger and every error', async () => {
        await ensureState(config, {
            key: 'pair',
            triggerId: 'trigger',
            setup: async ({ attempt }) => {
                throw new Error(`failure ${attempt}`)
            },
            ...noWait,
        }).catch(() => {})

        const status = readPairFailure(config, 'pair')
        expect(status?.triggeringTestId).toBe('trigger')
        expect(status?.attempts.map((a) => a.error)).toEqual([
            expect.stringContaining('failure 1'),
            expect.stringContaining('failure 2'),
        ])
    })

    it('honours a configured attempt count', async () => {
        let runs = 0
        const once = { ...config, setup: { ...config.setup, attempts: 1 } }

        await ensureState(once, {
            key: 'pair',
            triggerId: 't1',
            setup: async () => {
                runs++
                throw new Error('nope')
            },
            ...noWait,
        }).catch(() => {})

        expect(runs).toBe(1)
    })
})

describe('ensureState — waiting for another worker', () => {
    it('returns the state that appears while it waits', async () => {
        acquireSetupLock(runPaths(config).locksDir, 'pair', { nonce: 'other', attempt: 1 })
        let polls = 0

        const outcome = await ensureState(config, {
            key: 'pair',
            triggerId: 't1',
            setup: async () => {
                throw new Error('must not run')
            },
            sleep: async () => {
                polls++
                if (polls === 2) {
                    commitPairState(config, {
                        key: 'pair',
                        testId: 'ABCD1234EFGH5678',
                        attempt: 1,
                        setupMs: 1,
                        data: { from: 'other' },
                    })
                }
            },
        })

        expect(outcome).toMatchObject({ status: 'ready', setupRan: false })
        expect(outcome.status === 'ready' && outcome.data).toEqual({ from: 'other' })
    })

    it('does not burn attempt numbers while it waits', async () => {
        const locksDir = runPaths(config).locksDir
        acquireSetupLock(locksDir, 'pair', { nonce: 'other', attempt: 1 })
        let polls = 0

        await ensureState(config, {
            key: 'pair',
            triggerId: 't1',
            setup: async () => ({}),
            sleep: async () => {
                polls++
                if (polls === 5) {
                    commitPairState(config, {
                        key: 'pair',
                        testId: 'ABCD1234EFGH5678',
                        attempt: 1,
                        setupMs: 1,
                        data: {},
                    })
                }
            },
        })

        expect(highestClaimedAttempt(locksDir, 'pair')).toBe(0)
    })

    it('starts at attempt 1 when a waiter finally gets the lock', async () => {
        const locksDir = runPaths(config).locksDir
        acquireSetupLock(locksDir, 'pair', { nonce: 'other', attempt: 1 })
        let polls = 0

        await ensureState(config, {
            key: 'pair',
            triggerId: 't1',
            setup: async () => ({}),
            sleep: async () => {
                polls++
                if (polls === 3) {
                    releaseSetupLock(locksDir, 'pair', 'other')
                }
            },
        })

        expect(readAttempts(config).map((a) => a.attempt)).toEqual([1])
    })

    it('gives up with an error naming the pair', async () => {
        acquireSetupLock(runPaths(config).locksDir, 'pair', { nonce: 'other', attempt: 1 })
        let clock = 0

        await expect(
            ensureState(config, {
                key: 'pair',
                triggerId: 't1',
                setup: async () => ({}),
                sleep: async () => {
                    clock += 500
                },
                now: () => clock,
            }),
        ).rejects.toThrow(/pair/)
    })

    it('takes over a lock whose holder went silent', async () => {
        const locksDir = runPaths(config).locksDir
        acquireSetupLock(locksDir, 'pair', { nonce: 'dead', attempt: 1 })
        const longAgo = new Date(Date.now() - 60_000)
        fs.utimesSync(path.join(locksDir, 'pair.lock'), longAgo, longAgo)

        const outcome = await ensureState(config, {
            key: 'pair',
            triggerId: 't1',
            setup: async () => ({ recovered: true }),
            ...noWait,
        })

        expect(outcome.status).toBe('ready')
        expect(outcome.status === 'ready' && outcome.data).toEqual({ recovered: true })
    })

    it('carries on from the dead attempt number', async () => {
        const locksDir = runPaths(config).locksDir
        // A real holder claims its number, then dies still holding the lock.
        const dead = claimNextAttempt(locksDir, 'pair')
        acquireSetupLock(locksDir, 'pair', { nonce: 'dead-holder', attempt: dead })
        const longAgo = new Date(Date.now() - 60_000)
        fs.utimesSync(path.join(locksDir, 'pair.lock'), longAgo, longAgo)

        await ensureState(config, { key: 'pair', triggerId: 't1', setup: async () => ({}), ...noWait })

        expect(readAttempts(config).map((a) => a.attempt)).toEqual([2])
    })
})

describe('ensureState — a holder that was fenced out', () => {
    it('does not commit state after its lock was taken', async () => {
        const locksDir = runPaths(config).locksDir

        await ensureState(config, {
            key: 'pair',
            triggerId: 't1',
            setup: async () => {
                const longAgo = new Date(Date.now() - 60_000)
                fs.utimesSync(path.join(locksDir, 'pair.lock'), longAgo, longAgo)
                stealSetupLock(locksDir, 'pair', 'thief', 15_000)

                return { written: 'by a fenced holder' }
            },
            ...noWait,
            now: (() => {
                let clock = 0

                return () => (clock += 500)
            })(),
        }).catch(() => {})

        expect(readPairState(config, 'pair')).toBeUndefined()
    })

    it('does not write a terminal status after its lock was taken', async () => {
        const locksDir = runPaths(config).locksDir

        await ensureState(config, {
            key: 'pair',
            triggerId: 't1',
            setup: async () => {
                const longAgo = new Date(Date.now() - 60_000)
                fs.utimesSync(path.join(locksDir, 'pair.lock'), longAgo, longAgo)
                stealSetupLock(locksDir, 'pair', 'thief', 15_000)
                throw new Error('failed while fenced')
            },
            ...noWait,
            now: (() => {
                let clock = 0

                return () => (clock += 500)
            })(),
        }).catch(() => {})

        expect(readPairFailure(config, 'pair')).toBeUndefined()
    })
})

describe('ensureState — a setup that hangs', () => {
    it('abandons the attempt and retries', async () => {
        let starts = 0
        config = { ...config, setup: { ...config.setup, attemptTimeoutMs: 30 } }

        const outcome = await ensureState(config, {
            key: 'pair',
            triggerId: 't1',
            setup: async ({ signal }) => {
                starts++
                if (starts === 1) {
                    return new Promise((resolve) => {
                        signal.addEventListener('abort', () => resolve({ never: true }))
                    })
                }

                return { ok: true }
            },
            ...noWait,
        })

        expect(starts).toBe(2)
        expect(outcome.status === 'ready' && outcome.data).toEqual({ ok: true })
    })
})
