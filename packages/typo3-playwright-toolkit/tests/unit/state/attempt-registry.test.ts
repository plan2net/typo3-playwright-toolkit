import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { execFile } from 'node:child_process'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import {
    readAttempts,
    readAttemptsFrom,
    readRegisteredTestIds,
    recordAttemptOutcome,
    registerAttempt,
    registerSetupAttempt,
} from '#src/state/attempt-registry.js'
import { runLastActiveMs, runPaths } from '#src/state/run-namespace.js'
import { configForRun } from '../../helpers.js'

let tmpRoot: string

beforeEach(() => {
    tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-registry-'))
})

afterEach(() => {
    fs.rmSync(tmpRoot, { recursive: true, force: true })
})

describe('registerAttempt', () => {
    it('records an attempt that can be read back', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')

        registerAttempt(config, {
            key: 'tests_accordion-1234abcd',
            attempt: 1,
            testId: 'ABCD1234EFGH5678',
            nonce: 'deadbeef',
        })

        const attempts = readAttempts(config)
        expect(attempts).toHaveLength(1)
        expect(attempts[0].testId).toBe('ABCD1234EFGH5678')
        expect(attempts[0].attempt).toBe(1)
        expect(attempts[0].key).toBe('tests_accordion-1234abcd')
    })

    it('creates the run directory when it does not exist yet', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')

        registerAttempt(config, { key: 'k', attempt: 1, testId: 'ABCD1234EFGH5678', nonce: 'n' })

        expect(fs.existsSync(runPaths(config).attemptsFile)).toBe(true)
    })

    it('keeps every attempt of a pair', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')

        registerAttempt(config, { key: 'k', attempt: 1, testId: 'AAAA1111BBBB2222', nonce: 'n1' })
        registerAttempt(config, { key: 'k', attempt: 2, testId: 'CCCC3333DDDD4444', nonce: 'n2' })

        expect(readRegisteredTestIds(config)).toEqual(['AAAA1111BBBB2222', 'CCCC3333DDDD4444'])
    })
})

describe('liveness', () => {
    it('marks the run as active when an attempt is recorded', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'k', attempt: 1, testId: 'AAAA1111AAAA1111', nonce: 'n' })
        const runDir = runPaths(config).runDir
        const twoDaysAgo = new Date(Date.now() - 2 * 86_400_000)
        for (const entry of ['liveness', 'attempts.jsonl', '']) {
            const target = entry === '' ? runDir : path.join(runDir, entry)
            fs.utimesSync(target, twoDaysAgo, twoDaysAgo)
        }

        registerAttempt(config, { key: 'k2', attempt: 1, testId: 'BBBB2222BBBB2222', nonce: 'n' })

        expect(runLastActiveMs(runDir)).toBeGreaterThan(Date.now() - 60_000)
    })
})

describe('registerSetupAttempt', () => {
    it('records attempt 1 with a generated nonce', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')

        registerSetupAttempt(config, 'tests_demo-1234abcd', 'ABCD1234EFGH5678')

        const [record] = readAttempts(config)
        expect(record.attempt).toBe(1)
        expect(record.nonce).toMatch(/^[0-9a-f]{32}$/)
    })
})

describe('recordAttemptOutcome', () => {
    it('does not show up as an attempt', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'k', attempt: 1, testId: 'ABCD1234EFGH5678', nonce: 'n' })

        recordAttemptOutcome(config, { testId: 'ABCD1234EFGH5678', outcome: 'committed', durationMs: 1234 })

        expect(readAttempts(config)).toHaveLength(1)
    })
})

describe('readAttemptsFrom', () => {
    it('returns nothing when the file does not exist', () => {
        expect(readAttemptsFrom(path.join(tmpRoot, 'nope.jsonl'))).toEqual([])
    })

    it('skips a half-written last line', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'k', attempt: 1, testId: 'ABCD1234EFGH5678', nonce: 'n' })
        fs.appendFileSync(runPaths(config).attemptsFile, '{"type":"attempt","key":"trunc')

        expect(readAttempts(config)).toHaveLength(1)
    })

    it('skips valid JSON that is not an attempt', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        registerAttempt(config, { key: 'k', attempt: 1, testId: 'ABCD1234EFGH5678', nonce: 'n' })
        fs.appendFileSync(runPaths(config).attemptsFile, '{"hello":"world"}\n')

        expect(readAttempts(config)).toHaveLength(1)
    })
})

describe('concurrent appends', () => {
    it('does not interleave writes from separate processes', async () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        const attemptsFile = runPaths(config).attemptsFile
        fs.mkdirSync(path.dirname(attemptsFile), { recursive: true })

        const script = `
            const fs = require('fs')
            const file = process.argv[1]
            const tag = process.argv[2]
            for (let i = 0; i < 50; i++) {
                const line = JSON.stringify({
                    type: 'attempt', key: tag, attempt: i,
                    testId: (tag + String(i).padStart(14, '0')).slice(0, 16).toUpperCase(),
                    nonce: 'n', startedAt: new Date(0).toISOString(),
                }) + '\\n'
                fs.writeFileSync(file, line, { flag: 'a' })
            }
        `

        const runChild = (tag: string) =>
            new Promise<void>((resolve, reject) => {
                execFile(process.execPath, ['-e', script, attemptsFile, tag], (error) =>
                    error ? reject(new Error(error.message)) : resolve(),
                )
            })

        await Promise.all(['AA', 'BB', 'CC', 'DD'].map(runChild))

        expect(readAttempts(config)).toHaveLength(200)
    })
})
