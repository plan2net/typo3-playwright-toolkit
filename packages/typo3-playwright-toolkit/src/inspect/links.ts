import * as fs from 'fs'
import * as path from 'path'
import { readAttemptsFrom } from '../state/attempt-registry.js'
import { listRunIds, runLastActiveMs, runsRoot } from '../state/run-namespace.js'
import { inspectUrl } from './token.js'

export interface InspectLink {
    runId: string
    key: string
    name: string
    testId: string
    url: string
}

export function findStateDir(from: string): string | undefined {
    let directory = path.resolve(from)

    for (;;) {
        const candidate = path.join(directory, '.test-state')
        // The API secret is read from the parent of whatever matches here, so a
        // directory without runs/ resolves it against the wrong root.
        if (fs.existsSync(path.join(candidate, 'runs'))) {
            return candidate
        }

        const parent = path.dirname(directory)
        if (parent === directory) {
            return undefined
        }
        directory = parent
    }
}

export function inspectLinks(stateDir: string, secret: string, now: number = Date.now()): InspectLink[] {
    const runs = listRunIds(stateDir)
        .map((runId) => ({ runId, runDir: path.join(runsRoot(stateDir), runId) }))
        .sort((a, b) => runLastActiveMs(b.runDir) - runLastActiveMs(a.runDir))

    return runs.flatMap(({ runId, runDir }) => {
        const testingURL = readTestingUrl(path.join(runDir, 'meta.json'))
        if (undefined === testingURL) {
            return []
        }

        const attemptsFile = path.join(runDir, 'attempts.jsonl')
        const dropped = readDroppedTestIds(attemptsFile)

        return readAttemptsFrom(attemptsFile)
            .filter((attempt) => 'preflight' !== attempt.key && !dropped.has(attempt.testId))
            .map((attempt) => ({
                runId,
                key: attempt.key,
                name: attempt.name ?? attempt.key,
                testId: attempt.testId,
                url: inspectUrl(testingURL, secret, attempt.testId, now),
            }))
    })
}

function readDroppedTestIds(attemptsFile: string): Set<string> {
    const dropped = new Set<string>()
    let lines: string[]
    try {
        lines = fs.readFileSync(attemptsFile, 'utf-8').split('\n')
    } catch {
        return dropped
    }

    for (const line of lines) {
        try {
            const record = JSON.parse(line) as { type?: unknown; testId?: unknown }
            if ('dropped' === record.type && 'string' === typeof record.testId) {
                dropped.add(record.testId)
            }
        } catch {
            continue
        }
    }

    return dropped
}

function readTestingUrl(metaFile: string): string | undefined {
    try {
        const meta = JSON.parse(fs.readFileSync(metaFile, 'utf-8')) as { testingURL?: unknown }

        return 'string' === typeof meta.testingURL ? meta.testingURL : undefined
    } catch {
        return undefined
    }
}
