import { createHash, randomBytes } from 'node:crypto'
import * as fs from 'fs'
import * as path from 'path'
import type { ToolkitConfig } from '../config.js'
import { resolveRunId, RUN_ID_PATTERN } from './run-id.js'

export interface RunPaths {
    runId: string
    runDir: string
    scenariosDir: string
    failuresDir: string
    locksDir: string
    attemptsFile: string
    metaFile: string
}

export function runsRoot(stateDir: string): string {
    return path.join(stateDir, 'runs')
}

export function runPaths(config: ToolkitConfig): RunPaths {
    const runId = resolveRunId(config.runId)
    const runDir = path.join(runsRoot(config.paths.stateDir), runId)

    return {
        runId,
        runDir,
        scenariosDir: path.join(runDir, 'scenarios'),
        failuresDir: path.join(runDir, 'failures'),
        locksDir: path.join(runDir, 'locks'),
        attemptsFile: path.join(runDir, 'attempts.jsonl'),
        metaFile: path.join(runDir, 'meta.json'),
    }
}

export function ensureRunNamespace(config: ToolkitConfig): RunPaths {
    const paths = runPaths(config)

    for (const dir of [paths.runDir, paths.scenariosDir, paths.failuresDir, paths.locksDir]) {
        fs.mkdirSync(dir, { recursive: true })
    }

    return paths
}

/** How long after its last sign of life a run still counts as running. */
const OWNER_ACTIVE_MS = 30_000

export function prepareRun(config: ToolkitConfig): RunPaths {
    const paths = ensureRunNamespace(config)
    assertNamespaceIsFree(paths)

    if (!fs.existsSync(paths.metaFile)) {
        fs.writeFileSync(
            paths.metaFile,
            JSON.stringify(
                {
                    runId: paths.runId,
                    startedAt: new Date().toISOString(),
                    ownerPid: process.pid,
                    testingURL: config.testingURL,
                },
                null,
                2,
            ),
        )
    }

    return paths
}

/**
 * Two runs with the same run ID share their state and databases, and each teardown
 * deletes the other's records. Reusing the ID of a run that has finished is fine,
 * and is what pinning PW_RUN_ID is for.
 */
function assertNamespaceIsFree(paths: RunPaths): void {
    const owner = readOwnerPid(paths.metaFile)
    if (undefined === owner || owner === process.pid) {
        return
    }

    const liveness = path.join(paths.runDir, LIVENESS_FILE)
    let lastSign = 0
    try {
        lastSign = fs.statSync(liveness).mtimeMs
    } catch {
        return
    }

    if (Date.now() - lastSign < OWNER_ACTIVE_MS) {
        throw new Error(
            `[typo3-playwright-toolkit] Run "${paths.runId}" is already in use by process ${owner}. ` +
                'Two runs in one namespace overwrite each other\'s state and drop each other\'s ' +
                'databases. Unset PW_RUN_ID, or give this run its own value.',
        )
    }
}

function readOwnerPid(metaFile: string): number | undefined {
    try {
        const meta = JSON.parse(fs.readFileSync(metaFile, 'utf-8')) as { ownerPid?: unknown }

        return 'number' === typeof meta.ownerPid ? meta.ownerPid : undefined
    } catch {
        return undefined
    }
}

const SALT_FILE = 'salt'

/**
 * The random value every test ID of this run is built from. Without it the IDs
 * would follow from the run ID, and a run ID can be set by hand and guessed. A
 * test ID is enough to reach that test's database.
 *
 * Written with 'wx', so the first worker creates it and the others read it.
 */
export function runSalt(config: ToolkitConfig): string {
    const saltFile = path.join(ensureRunNamespace(config).runDir, SALT_FILE)

    try {
        fs.writeFileSync(saltFile, randomBytes(16).toString('hex'), { flag: 'wx' })
    } catch {
        // Another worker created it first.
    }

    return fs.readFileSync(saltFile, 'utf-8').trim()
}

export const LIVENESS_FILE = 'liveness'

export function touchRunLiveness(runDir: string): void {
    try {
        fs.writeFileSync(path.join(runDir, LIVENESS_FILE), String(Date.now()))
    } catch {
        // The run may be getting torn down elsewhere.
    }
}

/**
 * When the run was last active. Writing to a file inside a directory does not
 * update the directory's own time, so a busy run would look idle.
 */
export function runLastActiveMs(runDir: string): number {
    const candidates = [runDir, path.join(runDir, LIVENESS_FILE), path.join(runDir, 'attempts.jsonl')]

    let newest = 0
    for (const candidate of candidates) {
        try {
            newest = Math.max(newest, fs.statSync(candidate).mtimeMs)
        } catch {}
    }

    return newest
}

export function listRunIds(stateDir: string): string[] {
    const root = runsRoot(stateDir)
    if (!fs.existsSync(root)) {
        return []
    }

    // These names get joined into paths we delete, so screen them.
    return fs
        .readdirSync(root, { withFileTypes: true })
        .filter((entry) => entry.isDirectory())
        .map((entry) => entry.name)
        .filter((name) => RUN_ID_PATTERN.test(name))
}

/** The hash suffix keeps two paths apart when sanitizing collapses them. */
export function sanitizeScenarioKey(key: string): string {
    const base = key.replace(/[^A-Za-z0-9_-]/g, '_')
    const digest = createHash('sha256').update(key).digest('hex').slice(0, 8)

    return `${base}-${digest}`
}
