import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import type { ToolkitConfig } from '#src/config.js'
import type { RestoredSetup, SetupCacheClient, SetupCacheOutcome } from '#src/setup-cache/client.js'
import { configForRun } from '../../helpers.js'
import { ensureState } from '#src/state/ensure-state.js'
import { ensureRunNamespace, runPaths } from '#src/state/run-namespace.js'
import { readScenarioState } from '#src/state/scenario-state.js'
import { readLockOwner } from '#src/state/setup-lock.js'

let tmpRoot: string
let config: ToolkitConfig

const noWait = { sleep: async () => {} }

function cacheAnswering(restored: RestoredSetup): SetupCacheClient & { stored: Array<Record<string, unknown>> } {
    const stored: Array<Record<string, unknown>> = []

    return {
        stored,
        restore: async (): Promise<RestoredSetup> => restored,
        store: async (
            testId: string,
            key: string,
            state: Record<string, unknown>,
            onlyIfPresent = false,
        ): Promise<SetupCacheOutcome> => {
            stored.push({ testId, key, state, onlyIfPresent })

            return 'stored'
        },
    }
}

beforeEach(() => {
    tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-cache-state-'))
    config = {
        ...configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa'),
        setup: { pollMs: 1, waitTimeoutMs: 1_000, attemptTimeoutMs: 1_000 },
    }
    ensureRunNamespace(config)
})

afterEach(() => {
    fs.rmSync(tmpRoot, { recursive: true, force: true })
})

describe('ensureState with a setup cache', () => {
    it('skips the setup when the cache answers applied', async () => {
        let setupRuns = 0

        const outcome = await ensureState(config, {
            key: 'scenario',
            triggerId: 't1',
            setup: async () => {
                setupRuns++

                return { slug: '/rebuilt' }
            },
            setupCache: {
                key: 'a'.repeat(32),
                refreshOnly: false,
                client: cacheAnswering({ outcome: 'applied', state: { slug: '/from-the-cache' }, detail: '' }),
            },
            ...noWait,
        })

        expect(setupRuns).toBe(0)
        expect(outcome).toMatchObject({ status: 'ready', setupRan: false })
        expect(outcome.status === 'ready' && outcome.data).toEqual({ slug: '/from-the-cache' })
    })

    it('never reads the cache on a refresh-only run', async () => {
        let setupRuns = 0

        await ensureState(config, {
            key: 'scenario',
            triggerId: 't1',
            setup: async () => {
                setupRuns++

                return { slug: '/rebuilt' }
            },
            setupCache: {
                key: 'a'.repeat(32),
                refreshOnly: true,
                client: cacheAnswering({ outcome: 'applied', state: { slug: '/from-the-cache' }, detail: '' }),
            },
            ...noWait,
        })

        expect(setupRuns).toBe(1)
    })

    it('asks a refresh-only run to store only over an entry that exists', async () => {
        const cache = cacheAnswering({ outcome: 'applied', state: {}, detail: '' })

        await ensureState(config, {
            key: 'scenario',
            triggerId: 't1',
            setup: async () => ({ slug: '/rebuilt' }),
            setupCache: { key: 'a'.repeat(32), refreshOnly: true, client: cache },
            ...noWait,
        })

        expect(cache.stored).toEqual([
            { testId: expect.any(String), key: 'a'.repeat(32), state: { slug: '/rebuilt' }, onlyIfPresent: true },
        ])
    })

    it('stores before other workers can read the state and use the database', async () => {
        let committedWhenStored: boolean | undefined

        await ensureState(config, {
            key: 'scenario',
            triggerId: 't1',
            setup: async () => ({ slug: '/rebuilt' }),
            setupCache: {
                key: 'a'.repeat(32),
                refreshOnly: false,
                client: {
                    restore: async (): Promise<RestoredSetup> => ({ outcome: 'absent', state: {}, detail: '' }),
                    store: async (): Promise<SetupCacheOutcome> => {
                        committedWhenStored = undefined !== readScenarioState(config, 'scenario')

                        return 'stored'
                    },
                },
            },
            ...noWait,
        })

        expect(committedWhenStored).toBe(false)
    })

    it('builds and releases the lock when the cache cannot answer', async () => {
        const outcome = await ensureState(config, {
            key: 'scenario',
            triggerId: 't1',
            setup: async () => ({ slug: '/rebuilt' }),
            setupCache: {
                key: 'a'.repeat(32),
                refreshOnly: false,
                client: {
                    restore: async (): Promise<RestoredSetup> => {
                        throw new Error('the endpoint answered 404')
                    },
                    store: async (): Promise<SetupCacheOutcome> => {
                        throw new Error('the endpoint answered 404')
                    },
                },
            },
            ...noWait,
        })

        expect(outcome).toMatchObject({ status: 'ready', setupRan: true })
        expect(readLockOwner(runPaths(config).locksDir, 'scenario')).toBeUndefined()
    })

    it('says why it is building when the cache does not answer applied', async () => {
        const said: string[] = []
        const warn = vi.spyOn(console, 'warn').mockImplementation((line: string) => said.push(line))

        try {
            await ensureState(config, {
                key: 'scenario',
                triggerId: 't1',
                setup: async () => ({ slug: '/rebuilt' }),
                setupCache: {
                    key: 'a'.repeat(32),
                    refreshOnly: false,
                    client: cacheAnswering({ outcome: 'refused', state: {}, detail: 'it was built against another template' }),
                },
                ...noWait,
            })
        } finally {
            warn.mockRestore()
        }

        expect(said.join('\n')).toContain('scenario')
        expect(said.join('\n')).toContain('another template')
    })

    it('stores what the setup produced when the cache has nothing', async () => {
        const cache = cacheAnswering({ outcome: 'absent', state: {}, detail: '' })

        const outcome = await ensureState(config, {
            key: 'scenario',
            triggerId: 't1',
            setup: async () => ({ slug: '/rebuilt' }),
            setupCache: { key: 'a'.repeat(32), refreshOnly: false, client: cache },
            ...noWait,
        })

        expect(outcome).toMatchObject({ status: 'ready', setupRan: true })
        expect(cache.stored).toEqual([
            { testId: expect.any(String), key: 'a'.repeat(32), state: { slug: '/rebuilt' }, onlyIfPresent: false },
        ])
    })
})
