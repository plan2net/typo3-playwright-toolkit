import * as fs from 'fs'
import * as path from 'path'

function claimFile(locksDir: string, key: string, attempt: number): string {
    return path.join(locksDir, `${key}.attempt-${attempt}`)
}

export function highestClaimedAttempt(locksDir: string, key: string): number {
    if (!fs.existsSync(locksDir)) {
        return 0
    }

    const pattern = new RegExp(`^${key.replace(/[.*+?^${}()|[\]\\-]/g, '\\$&')}\\.attempt-(\\d+)$`)

    let highest = 0
    for (const entry of fs.readdirSync(locksDir)) {
        const match = pattern.exec(entry)
        if (match) {
            highest = Math.max(highest, Number(match[1]))
        }
    }

    return highest
}

/**
 * The claim files are never removed during a run: they are how a replacement
 * worker learns that a dead attempt already used its number, instead of
 * restarting at 1 on its half-built database.
 */
export function claimNextAttempt(locksDir: string, key: string): number {
    fs.mkdirSync(locksDir, { recursive: true })

    for (let attempt = highestClaimedAttempt(locksDir, key) + 1; ; attempt++) {
        let handle: number
        try {
            handle = fs.openSync(claimFile(locksDir, key, attempt), 'wx')
        } catch (error) {
            if ((error as NodeJS.ErrnoException).code === 'EEXIST') {
                continue
            }
            throw error
        }

        try {
            fs.writeFileSync(handle, JSON.stringify({ pid: process.pid, claimedAt: Date.now() }))
        } finally {
            fs.closeSync(handle)
        }

        return attempt
    }
}
