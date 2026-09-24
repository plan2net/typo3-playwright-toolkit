import * as fs from 'fs'
import * as path from 'path'

// Outside runs/: a passing run removes its run directory.
function testingUrlFile(stateDir: string): string {
    return path.join(stateDir, 'testing-url.json')
}

export function recordTestingUrl(stateDir: string, testingURL: string): void {
    fs.writeFileSync(testingUrlFile(stateDir), JSON.stringify({ testingURL }))
}

export function recordedTestingUrl(stateDir: string): string | undefined {
    let testingURL: unknown
    try {
        testingURL = (JSON.parse(fs.readFileSync(testingUrlFile(stateDir), 'utf-8')) as { testingURL?: unknown })
            .testingURL
    } catch {
        return undefined
    }

    return 'string' === typeof testingURL ? testingURL : undefined
}
