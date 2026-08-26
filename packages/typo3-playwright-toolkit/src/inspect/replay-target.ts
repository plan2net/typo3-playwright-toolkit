import * as fs from 'fs'
import * as path from 'path'
import { replayInspectUrl } from './token.js'

// Outside runs/: replay teardown removes its run directory, and a link has to
// stay mintable after that.
function targetFile(stateDir: string): string {
    return path.join(stateDir, 'replay.json')
}

export function recordReplayTarget(stateDir: string, testingURL: string): void {
    fs.mkdirSync(stateDir, { recursive: true })
    fs.writeFileSync(targetFile(stateDir), JSON.stringify({ testingURL }))
}

export function replayLink(stateDir: string, secret: string, now: number = Date.now()): string | undefined {
    let testingURL: unknown
    try {
        testingURL = (JSON.parse(fs.readFileSync(targetFile(stateDir), 'utf-8')) as { testingURL?: unknown })
            .testingURL
    } catch {
        return undefined
    }

    return 'string' === typeof testingURL ? replayInspectUrl(testingURL, secret, now) : undefined
}
