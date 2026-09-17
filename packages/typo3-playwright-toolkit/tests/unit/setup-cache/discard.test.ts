import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import { discardSetupCache } from '#src/setup-cache/discard.js'

let consumerRoot: string

beforeEach(() => {
    consumerRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-discard-'))
})

afterEach(() => {
    fs.rmSync(consumerRoot, { recursive: true, force: true })
})

describe('discardSetupCache', () => {
    it('removes the deltas and reports how many it dropped', () => {
        const directory = path.join(consumerRoot, 'var/playwright/setup-cache')
        fs.mkdirSync(directory, { recursive: true })
        fs.writeFileSync(path.join(directory, `${'a'.repeat(32)}.sql`), '-- one\n')
        fs.writeFileSync(path.join(directory, `${'b'.repeat(32)}.sql`), '-- two\n')

        expect(discardSetupCache(consumerRoot)).toBe(2)
        expect(fs.existsSync(directory)).toBe(false)
    })
})
