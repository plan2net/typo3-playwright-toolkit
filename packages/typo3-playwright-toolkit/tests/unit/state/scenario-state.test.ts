import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import type { ToolkitConfig } from '#src/config.js'
import { commitScenarioState, readScenarioState, readScenarioFailure, recordScenarioFailure } from '#src/state/scenario-state.js'
import { ensureRunNamespace, runPaths } from '#src/state/run-namespace.js'
import { configForRun } from '../../helpers.js'

let tmpRoot: string
let config: ToolkitConfig

beforeEach(() => {
    tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-scenario-state-'))
    config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
    ensureRunNamespace(config)
})

afterEach(() => {
    fs.rmSync(tmpRoot, { recursive: true, force: true })
})

describe('commitScenarioState', () => {
    it('round-trips the data the setup returned', () => {
        commitScenarioState(config, {
            key: 'scenario',
            testId: 'ABCD1234EFGH5678',
            attempt: 1,
            setupMs: 1200,
            data: { slug: '/accordion', ids: [1, 2] },
        })

        expect(readScenarioState<{ slug: string; ids: number[] }>(config, 'scenario')?.data).toEqual({
            slug: '/accordion',
            ids: [1, 2],
        })
    })

    it('records which attempt and test id won', () => {
        commitScenarioState(config, { key: 'scenario', testId: 'ABCD1234EFGH5678', attempt: 2, setupMs: 1, data: {} })

        expect(readScenarioState(config, 'scenario')).toMatchObject({
            runId: 'aaaaaaaaaaaaaaaa',
            key: 'scenario',
            testId: 'ABCD1234EFGH5678',
            attempt: 2,
        })
    })

    it('leaves no temporary file behind', () => {
        commitScenarioState(config, { key: 'scenario', testId: 'ABCD1234EFGH5678', attempt: 1, setupMs: 1, data: {} })

        expect(fs.readdirSync(runPaths(config).scenariosDir).filter((f) => f.includes('tmp'))).toEqual([])
    })

    it('refuses data that JSON cannot store faithfully', () => {
        expect(() =>
            commitScenarioState(config, {
                key: 'scenario',
                testId: 'ABCD1234EFGH5678',
                attempt: 1,
                setupMs: 1,
                data: { createdAt: new Date(0) },
            }),
        ).toThrow(/createdAt/)
    })

    it('writes nothing when the data is refused', () => {
        try {
            commitScenarioState(config, {
                key: 'scenario',
                testId: 'ABCD1234EFGH5678',
                attempt: 1,
                setupMs: 1,
                data: { bad: undefined },
            })
        } catch {
            // expected
        }

        expect(readScenarioState(config, 'scenario')).toBeUndefined()
    })
})

describe('readScenarioState', () => {
    it('is undefined when nothing was committed', () => {
        expect(readScenarioState(config, 'scenario')).toBeUndefined()
    })

    it('is undefined when the file is unreadable', () => {
        fs.writeFileSync(path.join(runPaths(config).scenariosDir, 'scenario.json'), '{ not json')

        expect(readScenarioState(config, 'scenario')).toBeUndefined()
    })
})

describe('recordScenarioFailure', () => {
    it('round-trips a terminal failure', () => {
        recordScenarioFailure(config, {
            key: 'scenario',
            triggeringTestId: 'playwright-test-id',
            attempts: [{ attempt: 1, testId: 'ABCD1234EFGH5678', error: 'boom', durationMs: 5 }],
        })

        expect(readScenarioFailure(config, 'scenario')).toMatchObject({
            scenarioKey: 'scenario',
            key: 'scenario',
            triggeringTestId: 'playwright-test-id',
        })
    })

    it('keeps every attempt, not just the last', () => {
        recordScenarioFailure(config, {
            key: 'scenario',
            triggeringTestId: 't',
            attempts: [
                { attempt: 1, testId: 'AAAA1111AAAA1111', error: 'first', durationMs: 1 },
                { attempt: 2, testId: 'BBBB2222BBBB2222', error: 'second', durationMs: 2 },
            ],
        })

        expect(readScenarioFailure(config, 'scenario')?.attempts.map((a) => a.error)).toEqual(['first', 'second'])
    })

    it('is undefined when nothing failed', () => {
        expect(readScenarioFailure(config, 'scenario')).toBeUndefined()
    })
})
