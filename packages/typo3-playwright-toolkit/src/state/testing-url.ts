import * as fs from 'fs'
import * as path from 'path'
import { readTestApiTarget, type TestApiTarget } from '../http/test-api.js'

// Outside runs/: a passing run removes its run directory.
function testingUrlFile(stateDir: string): string {
    return path.join(stateDir, 'testing-url.json')
}

export function recordTestingUrl(stateDir: string, target: TestApiTarget): void {
    fs.writeFileSync(
        testingUrlFile(stateDir),
        JSON.stringify({ testingURL: target.testingURL, backendPath: target.backendPath }),
    )
}

export function recordedTestApiTarget(stateDir: string): TestApiTarget | undefined {
    return readTestApiTarget(testingUrlFile(stateDir))
}
