import { randomBytes } from 'node:crypto'
import * as fs from 'fs'
import type { ToolkitConfig } from '../config.js'
import { ensureRunNamespace, runPaths, touchRunLiveness, type RunPaths } from './run-namespace.js'

export type AttemptOutcome = 'committed' | 'failed' | 'abandoned'

export interface AttemptRecord {
    type: 'attempt'
    key: string
    /** What a person calls the scenario. Absent in runs recorded before it existed. */
    name?: string
    attempt: number
    testId: string
    nonce: string
    startedAt: string
}

export interface AttemptOutcomeRecord {
    type: 'outcome'
    testId: string
    outcome: AttemptOutcome
    durationMs: number
    endedAt: string
}

/** One line per write with O_APPEND, so separate workers never interleave. */
function appendLine(paths: RunPaths, record: AttemptRecord | AttemptOutcomeRecord): void {
    fs.writeFileSync(paths.attemptsFile, `${JSON.stringify(record)}\n`, { flag: 'a' })
    touchRunLiveness(paths.runDir)
}

export function registerAttempt(
    config: ToolkitConfig,
    input: { key: string; name?: string; attempt: number; testId: string; nonce: string },
): void {
    const paths = ensureRunNamespace(config)

    appendLine(paths, {
        type: 'attempt',
        key: input.key,
        name: input.name ?? input.key,
        attempt: input.attempt,
        testId: input.testId,
        nonce: input.nonce,
        startedAt: new Date().toISOString(),
    })
}

export function registerSetupAttempt(config: ToolkitConfig, key: string, testId: string): void {
    registerAttempt(config, { key, attempt: 1, testId, nonce: randomBytes(16).toString('hex') })
}

export function recordAttemptOutcome(
    config: ToolkitConfig,
    input: { testId: string; outcome: AttemptOutcome; durationMs: number },
): void {
    const paths = ensureRunNamespace(config)

    appendLine(paths, {
        type: 'outcome',
        testId: input.testId,
        outcome: input.outcome,
        durationMs: input.durationMs,
        endedAt: new Date().toISOString(),
    })
}

function isAttemptRecord(value: unknown): value is AttemptRecord {
    if (typeof value !== 'object' || value === null) {
        return false
    }
    const record = value as Partial<AttemptRecord>

    return record.type === 'attempt' && typeof record.testId === 'string' && typeof record.key === 'string'
}

export function readAttemptsFrom(attemptsFile: string): AttemptRecord[] {
    if (!fs.existsSync(attemptsFile)) {
        return []
    }

    const records: AttemptRecord[] = []
    for (const line of fs.readFileSync(attemptsFile, 'utf-8').split('\n')) {
        if (line.trim() === '') {
            continue
        }
        try {
            const parsed: unknown = JSON.parse(line)
            if (isAttemptRecord(parsed)) {
                records.push(parsed)
            }
        } catch {
            // A crash mid-write leaves a partial last line.
        }
    }

    return records
}

export function readAttempts(config: ToolkitConfig): AttemptRecord[] {
    return readAttemptsFrom(runPaths(config).attemptsFile)
}

export function readRegisteredTestIds(config: ToolkitConfig): string[] {
    return [...new Set(readAttempts(config).map((record) => record.testId))]
}
