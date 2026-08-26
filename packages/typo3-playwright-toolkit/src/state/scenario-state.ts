import * as fs from 'fs'
import * as path from 'path'
import type { ToolkitConfig } from '../config.js'
import { assertJsonSafe } from './json-safe.js'
import { ensureRunNamespace } from './run-namespace.js'

export interface ScenarioStateRecord<S> {
    runId: string
    key: string
    testId: string
    attempt: number
    committedAt: string
    setupMs: number
    data: S
}

export interface ScenarioAttemptFailure {
    attempt: number
    testId: string
    error: string
    durationMs: number
}

export interface ScenarioFailureRecord {
    runId: string
    scenarioKey: string
    /** File name stem: a setup and a test failure of one scenario both need one. */
    key: string
    triggeringTestId: string
    recordedAt: string
    attempts: ScenarioAttemptFailure[]
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

export function commitScenarioState<S>(
    config: ToolkitConfig,
    input: { key: string; testId: string; attempt: number; setupMs: number; data: S },
): void {
    assertJsonSafe(input.data, `setup state for "${input.key}"`)

    const paths = ensureRunNamespace(config)
    const record: ScenarioStateRecord<S> = {
        runId: paths.runId,
        key: input.key,
        testId: input.testId,
        attempt: input.attempt,
        committedAt: new Date().toISOString(),
        setupMs: input.setupMs,
        data: input.data,
    }

    atomicWrite(path.join(paths.scenariosDir, `${input.key}.json`), JSON.stringify(record, null, 2))
}

export function readScenarioState<S>(config: ToolkitConfig, key: string): ScenarioStateRecord<S> | undefined {
    return readJson<ScenarioStateRecord<S>>(path.join(ensureRunNamespace(config).scenariosDir, `${key}.json`))
}

export function recordScenarioFailure(
    config: ToolkitConfig,
    input: { key: string; scenarioKey?: string; triggeringTestId: string; attempts: ScenarioAttemptFailure[] },
): void {
    const paths = ensureRunNamespace(config)
    const record: ScenarioFailureRecord = {
        runId: paths.runId,
        scenarioKey: input.scenarioKey ?? input.key,
        key: input.key,
        triggeringTestId: input.triggeringTestId,
        recordedAt: new Date().toISOString(),
        attempts: input.attempts,
    }

    atomicWrite(path.join(paths.failuresDir, `${input.key}.json`), JSON.stringify(record, null, 2))
}

export function readScenarioFailure(config: ToolkitConfig, key: string): ScenarioFailureRecord | undefined {
    return readJson<ScenarioFailureRecord>(path.join(ensureRunNamespace(config).failuresDir, `${key}.json`))
}
