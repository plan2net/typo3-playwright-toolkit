import * as fs from 'fs'
import * as path from 'path'

export interface LockOwner {
    nonce: string
    attempt: number
    pid: number
    startedAt: number
}

function lockFile(locksDir: string, key: string): string {
    return path.join(locksDir, `${key}.lock`)
}

export function acquireSetupLock(
    locksDir: string,
    key: string,
    owner: { nonce: string; attempt: number },
): boolean {
    fs.mkdirSync(locksDir, { recursive: true })

    let handle: number
    try {
        handle = fs.openSync(lockFile(locksDir, key), 'wx')
    } catch (error) {
        if ((error as NodeJS.ErrnoException).code === 'EEXIST') {
            return false
        }
        throw error
    }

    const record: LockOwner = {
        nonce: owner.nonce,
        attempt: owner.attempt,
        pid: process.pid,
        startedAt: Date.now(),
    }
    try {
        fs.writeFileSync(handle, JSON.stringify(record))
    } finally {
        fs.closeSync(handle)
    }

    return true
}

export function readLockOwner(locksDir: string, key: string): LockOwner | undefined {
    try {
        return JSON.parse(fs.readFileSync(lockFile(locksDir, key), 'utf-8')) as LockOwner
    } catch {
        return undefined
    }
}

/** False means another process took the lock, so nothing may be written. */
export function stillOwns(locksDir: string, key: string, nonce: string): boolean {
    return readLockOwner(locksDir, key)?.nonce === nonce
}

export function lockAgeMs(locksDir: string, key: string, now: number = Date.now()): number | undefined {
    try {
        return now - fs.statSync(lockFile(locksDir, key)).mtimeMs
    } catch {
        return undefined
    }
}

export function heartbeatSetupLock(locksDir: string, key: string, nonce: string): boolean {
    if (!stillOwns(locksDir, key, nonce)) {
        return false
    }

    const now = new Date()
    try {
        fs.utimesSync(lockFile(locksDir, key), now, now)
    } catch {
        return false
    }

    return true
}

/**
 * Takes over a lock whose owner stopped reporting. Renames the file instead of
 * deleting it, because a rename can only succeed once, so only one process wins.
 */
export function stealSetupLock(
    locksDir: string,
    key: string,
    stealerNonce: string,
    staleMs: number,
    now: number = Date.now(),
): boolean {
    const age = lockAgeMs(locksDir, key, now)
    if (age === undefined || age < staleMs) {
        return false
    }

    const stolen = path.join(locksDir, `${key}.lock.stale-${stealerNonce}`)
    try {
        fs.renameSync(lockFile(locksDir, key), stolen)
    } catch {
        return false
    }
    fs.rmSync(stolen, { force: true })

    return true
}

export function releaseSetupLock(locksDir: string, key: string, nonce: string): void {
    if (!stillOwns(locksDir, key, nonce)) {
        return
    }
    fs.rmSync(lockFile(locksDir, key), { force: true })
}
