import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import {
    acquireSetupLock,
    heartbeatSetupLock,
    lockAgeMs,
    readLockOwner,
    releaseSetupLock,
    stealSetupLock,
    stillOwns,
} from '#src/state/setup-lock.js'

let locksDir: string

function ageLock(key: string, ms = 60_000): void {
    const when = new Date(Date.now() - ms)
    fs.utimesSync(path.join(locksDir, `${key}.lock`), when, when)
}

beforeEach(() => {
    locksDir = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-lock-'))
})

afterEach(() => {
    fs.rmSync(locksDir, { recursive: true, force: true })
})

describe('acquireSetupLock', () => {
    it('succeeds when the lock is free', () => {
        expect(acquireSetupLock(locksDir, 'scenario', { nonce: 'a', attempt: 1 })).toBe(true)
    })

    it('fails when someone else holds it', () => {
        acquireSetupLock(locksDir, 'scenario', { nonce: 'a', attempt: 1 })

        expect(acquireSetupLock(locksDir, 'scenario', { nonce: 'b', attempt: 2 })).toBe(false)
    })

    it('records who holds it', () => {
        acquireSetupLock(locksDir, 'scenario', { nonce: 'a', attempt: 3 })

        expect(readLockOwner(locksDir, 'scenario')).toMatchObject({ nonce: 'a', attempt: 3 })
    })

    it('leaves another scenario free', () => {
        acquireSetupLock(locksDir, 'one', { nonce: 'a', attempt: 1 })

        expect(acquireSetupLock(locksDir, 'two', { nonce: 'b', attempt: 1 })).toBe(true)
    })
})

describe('readLockOwner', () => {
    it('is undefined when no lock is held', () => {
        expect(readLockOwner(locksDir, 'scenario')).toBeUndefined()
    })
})

describe('stillOwns', () => {
    it('is true for the holder', () => {
        acquireSetupLock(locksDir, 'scenario', { nonce: 'a', attempt: 1 })

        expect(stillOwns(locksDir, 'scenario', 'a')).toBe(true)
    })

    it('is false for anyone else', () => {
        acquireSetupLock(locksDir, 'scenario', { nonce: 'a', attempt: 1 })

        expect(stillOwns(locksDir, 'scenario', 'b')).toBe(false)
    })

    it('is false when the lock is gone', () => {
        expect(stillOwns(locksDir, 'scenario', 'a')).toBe(false)
    })
})

describe('heartbeatSetupLock', () => {
    it('moves the lock age back to zero', () => {
        acquireSetupLock(locksDir, 'scenario', { nonce: 'a', attempt: 1 })
        const longAgo = new Date(Date.now() - 60_000)
        fs.utimesSync(path.join(locksDir, 'scenario.lock'), longAgo, longAgo)

        expect(heartbeatSetupLock(locksDir, 'scenario', 'a')).toBe(true)
        expect(lockAgeMs(locksDir, 'scenario')).toBeLessThan(5_000)
    })

    it('refuses once the lock was taken away', () => {
        acquireSetupLock(locksDir, 'scenario', { nonce: 'a', attempt: 1 })
        ageLock('scenario')
        stealSetupLock(locksDir, 'scenario', 'b', 15_000)

        expect(heartbeatSetupLock(locksDir, 'scenario', 'a')).toBe(false)
    })
})

describe('stealSetupLock', () => {
    it('does nothing while the lock is fresh', () => {
        acquireSetupLock(locksDir, 'scenario', { nonce: 'a', attempt: 1 })

        expect(stealSetupLock(locksDir, 'scenario', 'b', 15_000)).toBe(false)
        expect(stillOwns(locksDir, 'scenario', 'a')).toBe(true)
    })

    it('frees the lock for the next holder', () => {
        acquireSetupLock(locksDir, 'scenario', { nonce: 'a', attempt: 1 })
        ageLock('scenario')
        stealSetupLock(locksDir, 'scenario', 'b', 15_000)

        expect(acquireSetupLock(locksDir, 'scenario', { nonce: 'b', attempt: 2 })).toBe(true)
    })

    it('leaves nothing behind', () => {
        acquireSetupLock(locksDir, 'scenario', { nonce: 'a', attempt: 1 })
        ageLock('scenario')
        stealSetupLock(locksDir, 'scenario', 'b', 15_000)

        expect(fs.readdirSync(locksDir).filter((f) => f.includes('stale'))).toEqual([])
    })

    it('only lets one stealer through', () => {
        acquireSetupLock(locksDir, 'scenario', { nonce: 'a', attempt: 1 })
        ageLock('scenario')

        const first = stealSetupLock(locksDir, 'scenario', 'b', 15_000)
        const second = stealSetupLock(locksDir, 'scenario', 'c', 15_000)

        expect(first).toBe(true)
        expect(second).toBe(false)
    })

    it('is false when there is no lock at all', () => {
        expect(stealSetupLock(locksDir, 'scenario', 'b', 0)).toBe(false)
    })
})

describe('releaseSetupLock', () => {
    it('frees the lock for the next holder', () => {
        acquireSetupLock(locksDir, 'scenario', { nonce: 'a', attempt: 1 })

        releaseSetupLock(locksDir, 'scenario', 'a')

        expect(acquireSetupLock(locksDir, 'scenario', { nonce: 'b', attempt: 2 })).toBe(true)
    })

    it('does not touch a lock that now belongs to someone else', () => {
        acquireSetupLock(locksDir, 'scenario', { nonce: 'a', attempt: 1 })
        ageLock('scenario')
        stealSetupLock(locksDir, 'scenario', 'b', 15_000)
        acquireSetupLock(locksDir, 'scenario', { nonce: 'b', attempt: 2 })

        releaseSetupLock(locksDir, 'scenario', 'a')

        expect(stillOwns(locksDir, 'scenario', 'b')).toBe(true)
    })

    it('is quiet when the lock is already gone', () => {
        expect(() => releaseSetupLock(locksDir, 'scenario', 'a')).not.toThrow()
    })
})
