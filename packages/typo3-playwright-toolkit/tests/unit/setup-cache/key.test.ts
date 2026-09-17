import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import { cacheKey } from '#src/setup-cache/key.js'

let testDir: string

beforeEach(() => {
    testDir = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-setup-cache-'))
    fs.writeFileSync(path.join(testDir, 'renders.spec.ts'), 'export const test = 1\n')
})

afterEach(() => {
    fs.rmSync(testDir, { recursive: true, force: true })
})

describe('cacheKey', () => {
    it('is what the extension accepts as a key', () => {
        expect(cacheKey(testDir, 'tests_renders_spec_ts-1a2b3c4d')).toMatch(/^[a-f0-9]{32}$/)
    })

    it('ignores installed packages', () => {
        const before = cacheKey(testDir, 'scenario')

        fs.mkdirSync(path.join(testDir, 'node_modules/a-package'), { recursive: true })
        fs.writeFileSync(path.join(testDir, 'node_modules/a-package/index.js'), 'module.exports = 1\n')

        expect(cacheKey(testDir, 'scenario')).toBe(before)
    })

    it('changes when a builder beside the spec directory changes', () => {
        fs.mkdirSync(path.join(testDir, 'utils/builders'), { recursive: true })
        fs.writeFileSync(path.join(testDir, 'utils/builders/quote.ts'), 'export const layout = 1\n')
        const before = cacheKey(testDir, 'scenario')

        fs.writeFileSync(path.join(testDir, 'utils/builders/quote.ts'), 'export const layout = 2\n')

        expect(cacheKey(testDir, 'scenario')).not.toBe(before)
    })

    it('changes when any file in the test directory changes', () => {
        const before = cacheKey(testDir, 'scenario')

        fs.writeFileSync(path.join(testDir, 'helpers.ts'), 'export const helper = 2\n')

        expect(cacheKey(testDir, 'scenario')).not.toBe(before)
    })
})
