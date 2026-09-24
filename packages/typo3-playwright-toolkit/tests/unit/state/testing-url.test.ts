import { afterEach, beforeEach, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import { prepareRun } from '#src/state/run-namespace.js'
import { recordedTestingUrl } from '#src/state/testing-url.js'
import { configForRun } from '../../helpers.js'

let tmpRoot: string

beforeEach(() => {
    tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-testing-url-'))
})

afterEach(() => {
    fs.rmSync(tmpRoot, { recursive: true, force: true })
})

it('keeps the testing url after the run directory is removed', () => {
    const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
    fs.rmSync(prepareRun(config).runDir, { recursive: true })

    expect(recordedTestingUrl(config.paths.stateDir)).toBe('https://example-testing.test')
})
