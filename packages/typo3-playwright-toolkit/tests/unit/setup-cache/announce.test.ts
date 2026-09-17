import { describe, expect, it } from 'vitest'
import { configForRun } from '../../helpers.js'
import { announceSetupCache } from '#src/setup-cache/announce.js'

describe('announceSetupCache', () => {
    it('says nothing unless the flag is set', () => {
        const lines: string[] = []

        announceSetupCache(configForRun('/tmp/consumer'), {}, (line) => lines.push(line))

        expect(lines).toEqual([])
    })

    it('says what the key does not cover when the flag is set', () => {
        const lines: string[] = []

        announceSetupCache(configForRun('/tmp/consumer'), { PW_REUSE_SETUP: '1' }, (line) => lines.push(line))

        expect(lines.join('\n')).toContain('PHP')
        expect(lines.join('\n')).toContain('clean --setup-cache')
    })

    it('refuses to start when replay mode is on too', () => {
        const config = { ...configForRun('/tmp/consumer'), replay: true }

        expect(() => announceSetupCache(config, { PW_REUSE_SETUP: '1' }, () => {})).toThrow(/replay/i)
    })
})
