import * as fs from 'fs'
import * as path from 'path'

// Outside runs/: replay teardown removes its run directory.
function targetFile(stateDir: string): string {
    return path.join(stateDir, 'replay.json')
}

export function recordReplayTarget(stateDir: string, testingURL: string): void {
    fs.mkdirSync(stateDir, { recursive: true })
    fs.writeFileSync(targetFile(stateDir), JSON.stringify({ testingURL }))
}

export function replayTestingUrl(stateDir: string): string | undefined {
    let testingURL: unknown
    try {
        testingURL = (JSON.parse(fs.readFileSync(targetFile(stateDir), 'utf-8')) as { testingURL?: unknown })
            .testingURL
    } catch {
        return undefined
    }

    return 'string' === typeof testingURL ? testingURL : undefined
}
