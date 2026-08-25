import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import type { ToolkitConfig } from '#src/config.js'
import { commitPairState, readPairState, readPairFailure, recordPairFailure } from '#src/state/pair-state.js'
import { ensureRunNamespace, runPaths } from '#src/state/run-namespace.js'
import { configForRun } from '../../helpers.js'

let tmpRoot: string
let config: ToolkitConfig

beforeEach(() => {
    tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-pair-state-'))
    config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
    ensureRunNamespace(config)
})

afterEach(() => {
    fs.rmSync(tmpRoot, { recursive: true, force: true })
})

describe('commitPairState', () => {
    it('round-trips the data the setup returned', () => {
        commitPairState(config, {
            key: 'pair',
            testId: 'ABCD1234EFGH5678',
            attempt: 1,
            setupMs: 1200,
            data: { slug: '/accordion', ids: [1, 2] },
        })

        expect(readPairState<{ slug: string; ids: number[] }>(config, 'pair')?.data).toEqual({
            slug: '/accordion',
            ids: [1, 2],
        })
    })

    it('records which attempt and test id won', () => {
        commitPairState(config, { key: 'pair', testId: 'ABCD1234EFGH5678', attempt: 2, setupMs: 1, data: {} })

        expect(readPairState(config, 'pair')).toMatchObject({
            runId: 'aaaaaaaaaaaaaaaa',
            key: 'pair',
            testId: 'ABCD1234EFGH5678',
            attempt: 2,
        })
    })

    it('leaves no temporary file behind', () => {
        commitPairState(config, { key: 'pair', testId: 'ABCD1234EFGH5678', attempt: 1, setupMs: 1, data: {} })

        expect(fs.readdirSync(runPaths(config).pairsDir).filter((f) => f.includes('tmp'))).toEqual([])
    })

    it('refuses data that JSON cannot store faithfully', () => {
        expect(() =>
            commitPairState(config, {
                key: 'pair',
                testId: 'ABCD1234EFGH5678',
                attempt: 1,
                setupMs: 1,
                data: { createdAt: new Date(0) },
            }),
        ).toThrow(/createdAt/)
    })

    it('writes nothing when the data is refused', () => {
        try {
            commitPairState(config, {
                key: 'pair',
                testId: 'ABCD1234EFGH5678',
                attempt: 1,
                setupMs: 1,
                data: { bad: undefined },
            })
        } catch {
            // expected
        }

        expect(readPairState(config, 'pair')).toBeUndefined()
    })
})

describe('readPairState', () => {
    it('is undefined when nothing was committed', () => {
        expect(readPairState(config, 'pair')).toBeUndefined()
    })

    it('is undefined when the file is unreadable', () => {
        fs.writeFileSync(path.join(runPaths(config).pairsDir, 'pair.json'), '{ not json')

        expect(readPairState(config, 'pair')).toBeUndefined()
    })
})

describe('recordPairFailure', () => {
    it('round-trips a terminal failure', () => {
        recordPairFailure(config, {
            key: 'pair',
            triggeringTestId: 'playwright-test-id',
            attempts: [{ attempt: 1, testId: 'ABCD1234EFGH5678', error: 'boom', durationMs: 5 }],
        })

        expect(readPairFailure(config, 'pair')).toMatchObject({
            pairKey: 'pair',
            key: 'pair',
            triggeringTestId: 'playwright-test-id',
        })
    })

    it('keeps every attempt, not just the last', () => {
        recordPairFailure(config, {
            key: 'pair',
            triggeringTestId: 't',
            attempts: [
                { attempt: 1, testId: 'AAAA1111AAAA1111', error: 'first', durationMs: 1 },
                { attempt: 2, testId: 'BBBB2222BBBB2222', error: 'second', durationMs: 2 },
            ],
        })

        expect(readPairFailure(config, 'pair')?.attempts.map((a) => a.error)).toEqual(['first', 'second'])
    })

    it('is undefined when nothing failed', () => {
        expect(readPairFailure(config, 'pair')).toBeUndefined()
    })
})
