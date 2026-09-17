import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import { configForRun } from '../../helpers.js'
import { setupCacheUse } from '#src/setup-cache/use.js'

let suiteRoot: string

beforeEach(() => {
    suiteRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-cache-use-'))
    fs.writeFileSync(path.join(suiteRoot, 'playwright.config.ts'), 'export default {}\n')
})

afterEach(() => {
    fs.rmSync(suiteRoot, { recursive: true, force: true })
})

describe('setupCacheUse', () => {
    it('only refreshes what is already cached unless the flag is set', () => {
        const use = setupCacheUse(configForRun('/tmp/consumer'), suiteRoot, 'scenario', {})

        expect(use?.refreshOnly).toBe(true)
    })

    it('carries a key the extension accepts when the flag is set', () => {
        const use = setupCacheUse(configForRun('/tmp/consumer'), suiteRoot, 'scenario', { PW_REUSE_SETUP: '1' })

        expect(use?.key).toMatch(/^[a-f0-9]{32}$/)
    })

    it('stays off in replay mode, where every scenario shares one database', () => {
        const config = { ...configForRun('/tmp/consumer'), replay: true }

        expect(setupCacheUse(config, suiteRoot, 'scenario', { PW_REUSE_SETUP: '1' })).toBeUndefined()
    })
})
