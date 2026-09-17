import * as fs from 'fs'
import * as path from 'path'

export function discardSetupCache(consumerRoot: string): number {
    const directory = path.join(consumerRoot, 'var/playwright/setup-cache')

    let dropped = 0
    try {
        dropped = fs.readdirSync(directory).filter((entry) => entry.endsWith('.sql')).length
    } catch {
        return 0
    }

    fs.rmSync(directory, { recursive: true, force: true })

    return dropped
}
