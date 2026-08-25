import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { execFile } from 'node:child_process'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import { claimNextAttempt, highestClaimedAttempt } from '#src/state/attempt-claim.js'

let locksDir: string

beforeEach(() => {
    locksDir = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-claim-'))
})

afterEach(() => {
    fs.rmSync(locksDir, { recursive: true, force: true })
})

describe('claimNextAttempt', () => {
    it('starts at attempt 1', () => {
        expect(claimNextAttempt(locksDir, 'pair')).toBe(1)
    })

    it('counts up so a replacement never reuses a dead attempt', () => {
        claimNextAttempt(locksDir, 'pair')

        expect(claimNextAttempt(locksDir, 'pair')).toBe(2)
        expect(claimNextAttempt(locksDir, 'pair')).toBe(3)
    })

    it('keeps counts separate per pair', () => {
        claimNextAttempt(locksDir, 'one')
        claimNextAttempt(locksDir, 'one')

        expect(claimNextAttempt(locksDir, 'two')).toBe(1)
    })

    it('survives the death of the process that claimed', () => {
        claimNextAttempt(locksDir, 'pair')

        // Nothing is cleaned up on exit; the files on disk are the record.
        expect(highestClaimedAttempt(locksDir, 'pair')).toBe(1)
    })

    it('creates the locks directory when missing', () => {
        const missing = path.join(locksDir, 'nested', 'locks')

        expect(claimNextAttempt(missing, 'pair')).toBe(1)
    })
})

describe('highestClaimedAttempt', () => {
    it('is 0 when nothing was claimed', () => {
        expect(highestClaimedAttempt(locksDir, 'pair')).toBe(0)
    })

    it('ignores another pair whose name shares a prefix', () => {
        claimNextAttempt(locksDir, 'pair-extra')

        expect(highestClaimedAttempt(locksDir, 'pair')).toBe(0)
    })
})

describe('claimNextAttempt across processes', () => {
    it('never hands the same attempt to two claimants', async () => {
        const script = `
            const fs = require('fs')
            const crypto = require('crypto')
            const dir = process.argv[1]
            const results = []
            for (let i = 0; i < 20; i++) {
                let attempt = 0
                for (const entry of fs.readdirSync(dir)) {
                    const match = /^pair\\.attempt-(\\d+)$/.exec(entry)
                    if (match) attempt = Math.max(attempt, Number(match[1]))
                }
                for (let next = attempt + 1; ; next++) {
                    try {
                        const fd = fs.openSync(dir + '/pair.attempt-' + next, 'wx')
                        fs.writeFileSync(fd, JSON.stringify({ nonce: crypto.randomBytes(16).toString('hex') }))
                        fs.closeSync(fd)
                        results.push(next)
                        break
                    } catch (error) {
                        if (error.code !== 'EEXIST') throw error
                    }
                }
            }
            process.stdout.write(JSON.stringify(results))
        `

        const runChild = (): Promise<number[]> =>
            new Promise((resolve, reject) => {
                execFile(process.execPath, ['-e', script, locksDir], (error, stdout) =>
                    error ? reject(new Error(error.message)) : resolve(JSON.parse(stdout)),
                )
            })

        const claimed = (await Promise.all([runChild(), runChild(), runChild()])).flat()

        expect(claimed).toHaveLength(60)
        expect(new Set(claimed).size).toBe(60)
    })
})
