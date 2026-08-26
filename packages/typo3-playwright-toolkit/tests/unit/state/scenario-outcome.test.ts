import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import type { ToolkitConfig } from '#src/config.js'
import { configForRun } from '../../helpers.js'
import { applyScenarioOutcome, recordTestFailure } from '#src/state/scenario-outcome.js'
import { readScenarioFailure } from '#src/state/scenario-state.js'
import { ensureRunNamespace } from '#src/state/run-namespace.js'

let tmpRoot: string
let config: ToolkitConfig

beforeEach(() => {
    tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-scenario-outcome-'))
    config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
    ensureRunNamespace(config)
})

afterEach(() => {
    fs.rmSync(tmpRoot, { recursive: true, force: true })
})

describe('applyScenarioOutcome', () => {
    it('hands back the data when the setup is ready', () => {
        const skipped: string[] = []

        const data = applyScenarioOutcome(
            { status: 'ready', testId: 'ABCD1234EFGH5678', attempt: 1, data: { a: 1 }, setupRan: true, waitedMs: 0 },
            (reason) => skipped.push(reason),
        )

        expect(data).toEqual({ a: 1 })
        expect(skipped).toEqual([])
    })

    it('skips with the reason when the scenario already failed', () => {
        const skipped: string[] = []

        expect(() =>
            applyScenarioOutcome({ status: 'skip', reason: 'setup for "scenario" failed: boom' }, (reason) => {
                // Playwright's skip aborts the test by throwing; so does this.
                skipped.push(reason)
                throw new Error('skipped')
            }),
        ).toThrow(/skipped/)

        expect(skipped).toEqual(['setup for "scenario" failed: boom'])
    })

    it('throws if skipping did not stop the test', () => {
        expect(() => applyScenarioOutcome({ status: 'skip', reason: 'nope' }, () => {})).toThrow(/nope/)
    })
})

describe('recordTestFailure', () => {
    it('records a failure teardown can find', () => {
        recordTestFailure(config, 'scenario', 'expected 3 items, saw 2')

        expect(readScenarioFailure(config, 'scenario__test')?.scenarioKey).toBe('scenario')
    })

    it('keeps the reason', () => {
        recordTestFailure(config, 'scenario', 'expected 3 items, saw 2')

        expect(readScenarioFailure(config, 'scenario__test')?.attempts[0].error).toBe('expected 3 items, saw 2')
    })

    it('does not disturb the setup status of the same scenario', () => {
        recordTestFailure(config, 'scenario', 'boom')

        expect(readScenarioFailure(config, 'scenario')).toBeUndefined()
    })
})
