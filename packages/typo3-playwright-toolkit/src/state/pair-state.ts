import * as fs from 'fs'
import * as path from 'path'
import type { ToolkitConfig } from '../config.js'
import { assertJsonSafe } from './json-safe.js'
import { ensureRunNamespace } from './run-namespace.js'

export interface PairStateRecord<S> {
    runId: string
    key: string
    testId: string
    attempt: number
    committedAt: string
    setupMs: number
    data: S
}

export interface PairAttemptFailure {
    attempt: number
    testId: string
    error: string
    durationMs: number
}

export interface PairFailureRecord {
    runId: string
    pairKey: string
    /** File name stem: a setup and a verify failure of one pair both need one. */
    key: string
    triggeringTestId: string
    recordedAt: string
    attempts: PairAttemptFailure[]
}

function atomicWrite(target: string, content: string): void {
    const tmp = `${target}.${process.pid}.${Date.now()}.tmp`
    fs.writeFileSync(tmp, content)
    fs.renameSync(tmp, target)
}

function readJson<T>(file: string): T | undefined {
    try {
        return JSON.parse(fs.readFileSync(file, 'utf-8')) as T
    } catch {
        return undefined
    }
}

export function commitPairState<S>(
    config: ToolkitConfig,
    input: { key: string; testId: string; attempt: number; setupMs: number; data: S },
): void {
    assertJsonSafe(input.data, `setup state for "${input.key}"`)

    const paths = ensureRunNamespace(config)
    const record: PairStateRecord<S> = {
        runId: paths.runId,
        key: input.key,
        testId: input.testId,
        attempt: input.attempt,
        committedAt: new Date().toISOString(),
        setupMs: input.setupMs,
        data: input.data,
    }

    atomicWrite(path.join(paths.pairsDir, `${input.key}.json`), JSON.stringify(record, null, 2))
}

export function readPairState<S>(config: ToolkitConfig, key: string): PairStateRecord<S> | undefined {
    return readJson<PairStateRecord<S>>(path.join(ensureRunNamespace(config).pairsDir, `${key}.json`))
}

export function recordPairFailure(
    config: ToolkitConfig,
    input: { key: string; pairKey?: string; triggeringTestId: string; attempts: PairAttemptFailure[] },
): void {
    const paths = ensureRunNamespace(config)
    const record: PairFailureRecord = {
        runId: paths.runId,
        pairKey: input.pairKey ?? input.key,
        key: input.key,
        triggeringTestId: input.triggeringTestId,
        recordedAt: new Date().toISOString(),
        attempts: input.attempts,
    }

    atomicWrite(path.join(paths.failuresDir, `${input.key}.json`), JSON.stringify(record, null, 2))
}

export function readPairFailure(config: ToolkitConfig, key: string): PairFailureRecord | undefined {
    return readJson<PairFailureRecord>(path.join(ensureRunNamespace(config).failuresDir, `${key}.json`))
}
