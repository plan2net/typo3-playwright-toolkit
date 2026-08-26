import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import { recordReplayTarget, replayLink } from '#src/inspect/replay-target.js'

let stateDir: string

beforeEach(() => {
    stateDir = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-replay-link-'))
})

afterEach(() => {
    fs.rmSync(stateDir, { recursive: true, force: true })
})

describe('replayLink', () => {
    // Replay teardown removes the run directory, where every other link finds its
    // URL, so a re-mintable link needs a record that outlives the run.
    it('mints a link from the record the run left behind', () => {
        recordReplayTarget(stateDir, 'https://example-testing.test')

        const link = replayLink(stateDir, 'shh', 1_700_000_000_000)

        expect(link).toContain('https://example-testing.test/typo3/test-api/inspect?replay=1&t=')
    })

    it('answers nothing when no replay has run', () => {
        expect(replayLink(stateDir, 'shh', 1_700_000_000_000)).toBeUndefined()
    })

    it('answers nothing when the record is unreadable', () => {
        fs.writeFileSync(path.join(stateDir, 'replay.json'), 'not json')

        expect(replayLink(stateDir, 'shh', 1_700_000_000_000)).toBeUndefined()
    })

    it('keeps only the newest target', () => {
        recordReplayTarget(stateDir, 'https://old-testing.test')
        recordReplayTarget(stateDir, 'https://new-testing.test')

        expect(replayLink(stateDir, 'shh', 1_700_000_000_000)).toContain('https://new-testing.test/')
    })
})
