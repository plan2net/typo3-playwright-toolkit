import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import type { ToolkitConfig } from '#src/config.js'
import { configForRun } from '../../helpers.js'
import { applyPairOutcome, recordPairVerifyFailure } from '#src/state/pair-outcome.js'
import { readPairFailure } from '#src/state/pair-state.js'
import { ensureRunNamespace } from '#src/state/run-namespace.js'

let tmpRoot: string
let config: ToolkitConfig

beforeEach(() => {
    tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-pair-outcome-'))
    config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
    ensureRunNamespace(config)
})

afterEach(() => {
    fs.rmSync(tmpRoot, { recursive: true, force: true })
})

describe('applyPairOutcome', () => {
    it('hands back the data when the setup is ready', () => {
        const skipped: string[] = []

        const data = applyPairOutcome(
            { status: 'ready', testId: 'ABCD1234EFGH5678', attempt: 1, data: { a: 1 }, setupRan: true, waitedMs: 0 },
            (reason) => skipped.push(reason),
        )

        expect(data).toEqual({ a: 1 })
        expect(skipped).toEqual([])
    })

    it('skips with the reason when the pair already failed', () => {
        const skipped: string[] = []

        expect(() =>
            applyPairOutcome({ status: 'skip', reason: 'setup for "pair" failed: boom' }, (reason) => {
                // Playwright's skip aborts the test by throwing; so does this.
                skipped.push(reason)
                throw new Error('skipped')
            }),
        ).toThrow(/skipped/)

        expect(skipped).toEqual(['setup for "pair" failed: boom'])
    })

    it('throws if skipping did not stop the test', () => {
        expect(() => applyPairOutcome({ status: 'skip', reason: 'nope' }, () => {})).toThrow(/nope/)
    })
})

describe('recordPairVerifyFailure', () => {
    it('records a failure teardown can find', () => {
        recordPairVerifyFailure(config, 'pair', 'expected 3 items, saw 2')

        expect(readPairFailure(config, 'pair__verify')?.pairKey).toBe('pair')
    })

    it('keeps the reason', () => {
        recordPairVerifyFailure(config, 'pair', 'expected 3 items, saw 2')

        expect(readPairFailure(config, 'pair__verify')?.attempts[0].error).toBe('expected 3 items, saw 2')
    })

    it('does not disturb the setup status of the same pair', () => {
        recordPairVerifyFailure(config, 'pair', 'boom')

        expect(readPairFailure(config, 'pair')).toBeUndefined()
    })
})
