import * as fs from 'fs'
import * as path from 'path'
import { readTestApiTarget, type TestApiTarget } from '../http/test-api.js'

// Outside runs/: replay teardown removes its run directory.
function targetFile(stateDir: string): string {
    return path.join(stateDir, 'replay.json')
}

export function recordReplayTarget(stateDir: string, target: TestApiTarget): void {
    fs.mkdirSync(stateDir, { recursive: true })
    fs.writeFileSync(
        targetFile(stateDir),
        JSON.stringify({ testingURL: target.testingURL, backendPath: target.backendPath }),
    )
}

export function replayTarget(stateDir: string): TestApiTarget | undefined {
    return readTestApiTarget(targetFile(stateDir))
}
