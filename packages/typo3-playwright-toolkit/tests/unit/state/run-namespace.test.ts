import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import {
    ensureRunNamespace,
    listRunIds,
    prepareRun,
    runLastActiveMs,
    runPaths,
    runSalt,
    sanitizeScenarioKey,
    touchRunLiveness,
} from '#src/state/run-namespace.js'
import { configForRun } from '../../helpers.js'

let tmpRoot: string

beforeEach(() => {
    tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-run-ns-'))
})

afterEach(() => {
    fs.rmSync(tmpRoot, { recursive: true, force: true })
})

describe('runPaths', () => {
    it('puts everything under stateDir/runs/<runId>', () => {
        const paths = runPaths(configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa'))

        expect(paths.runDir).toBe(path.join(tmpRoot, '.test-state', 'runs', 'aaaaaaaaaaaaaaaa'))
        expect(paths.scenariosDir).toBe(path.join(paths.runDir, 'scenarios'))
        expect(paths.failuresDir).toBe(path.join(paths.runDir, 'failures'))
        expect(paths.locksDir).toBe(path.join(paths.runDir, 'locks'))
        expect(paths.attemptsFile).toBe(path.join(paths.runDir, 'attempts.jsonl'))
        expect(paths.metaFile).toBe(path.join(paths.runDir, 'meta.json'))
    })
})

describe('ensureRunNamespace', () => {
    it('creates every subdirectory', () => {
        const paths = ensureRunNamespace(configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa'))

        expect(fs.existsSync(paths.scenariosDir)).toBe(true)
        expect(fs.existsSync(paths.failuresDir)).toBe(true)
        expect(fs.existsSync(paths.locksDir)).toBe(true)
    })

    it('can be called twice', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        ensureRunNamespace(config)

        expect(() => ensureRunNamespace(config)).not.toThrow()
    })

    it('leaves another run untouched', () => {
        const first = ensureRunNamespace(configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa'))
        fs.writeFileSync(path.join(first.scenariosDir, 'demo.json'), '{}')

        ensureRunNamespace(configForRun(tmpRoot, 'bbbbbbbbbbbbbbbb'))

        expect(fs.existsSync(path.join(first.scenariosDir, 'demo.json'))).toBe(true)
    })
})

describe('prepareRun', () => {
    it('writes run metadata', () => {
        const paths = prepareRun(configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa'))
        const meta = JSON.parse(fs.readFileSync(paths.metaFile, 'utf-8'))

        expect(meta.runId).toBe('aaaaaaaaaaaaaaaa')
        expect(typeof meta.startedAt).toBe('string')
    })

    // The inspect command builds links long after the run, without loading the
    // consumer's TypeScript config, so the run has to record where the site is.
    it('records the testing url the run used', () => {
        const paths = prepareRun(configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa'))
        const meta = JSON.parse(fs.readFileSync(paths.metaFile, 'utf-8'))

        expect(meta.testingURL).toBe('https://example-testing.test')
    })

    it('keeps the original startedAt when called again', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        const first = JSON.parse(fs.readFileSync(prepareRun(config).metaFile, 'utf-8'))

        const second = JSON.parse(fs.readFileSync(prepareRun(config).metaFile, 'utf-8'))

        expect(second.startedAt).toBe(first.startedAt)
    })
})

describe('prepareRun — a namespace another run is using', () => {
    /**
     * Two runs sharing one PW_RUN_ID share scenario state and databases, and each
     * teardown deletes the other's registry. The ddev help suggests setting it in
     * web_environment, where it stays the same for every run.
     */
    it('refuses a namespace a live run of another process owns', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        const paths = prepareRun(config)
        fs.writeFileSync(paths.metaFile, JSON.stringify({ ownerPid: process.pid + 1 }))
        touchRunLiveness(paths.runDir)

        expect(() => prepareRun(config)).toThrow(/PW_RUN_ID/)
    })

    it('lets the owning process prepare again, as the editor does', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        touchRunLiveness(prepareRun(config).runDir)

        expect(() => prepareRun(config)).not.toThrow()
    })

    it('reuses the namespace of a run that is no longer active', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
        const paths = prepareRun(config)
        fs.writeFileSync(paths.metaFile, JSON.stringify({ ownerPid: process.pid + 1 }))
        const longAgo = new Date(Date.now() - 600_000)
        fs.writeFileSync(path.join(paths.runDir, 'liveness'), '0')
        fs.utimesSync(path.join(paths.runDir, 'liveness'), longAgo, longAgo)

        expect(() => prepareRun(config)).not.toThrow()
    })
})

describe('runSalt', () => {
    it('gives every worker of one run the same value', () => {
        const config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')

        expect(runSalt(config)).toBe(runSalt(config))
    })

    it('gives two runs different values', () => {
        const mine = runSalt(configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa'))
        const theirs = runSalt(configForRun(tmpRoot, 'bbbbbbbbbbbbbbbb'))

        expect(mine).not.toBe(theirs)
    })

    // Test IDs are derived from it, and a test ID alone points a request at that
    // test's database — so it must not be the run ID, which may be pinned.
    it('is not the run id', () => {
        const salt = runSalt(configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa'))

        expect(salt).not.toBe('aaaaaaaaaaaaaaaa')
        expect(salt).toMatch(/^[0-9a-f]{32}$/)
    })
})

describe('listRunIds', () => {
    it('lists existing run directories', () => {
        ensureRunNamespace(configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa'))
        ensureRunNamespace(configForRun(tmpRoot, 'bbbbbbbbbbbbbbbb'))

        expect(listRunIds(path.join(tmpRoot, '.test-state')).sort()).toEqual([
            'aaaaaaaaaaaaaaaa',
            'bbbbbbbbbbbbbbbb',
        ])
    })

    it('returns nothing when no runs exist', () => {
        expect(listRunIds(path.join(tmpRoot, '.test-state'))).toEqual([])
    })

    it('ignores a directory that is not a valid run id', () => {
        ensureRunNamespace(configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa'))
        fs.mkdirSync(path.join(tmpRoot, '.test-state', 'runs', 'not a run id!'), { recursive: true })

        expect(listRunIds(path.join(tmpRoot, '.test-state'))).toEqual(['aaaaaaaaaaaaaaaa'])
    })
})

describe('runLastActiveMs', () => {
    it('reports recent activity even when the directory itself is old', () => {
        const paths = ensureRunNamespace(configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa'))
        const twoDaysAgo = new Date(Date.now() - 2 * 86_400_000)
        fs.utimesSync(paths.runDir, twoDaysAgo, twoDaysAgo)

        touchRunLiveness(paths.runDir)

        expect(runLastActiveMs(paths.runDir)).toBeGreaterThan(Date.now() - 60_000)
    })

    it('falls back to the directory when nothing else is there', () => {
        const paths = ensureRunNamespace(configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa'))

        expect(runLastActiveMs(paths.runDir)).toBeGreaterThan(0)
    })

    it('returns 0 for a run that does not exist', () => {
        expect(runLastActiveMs(path.join(tmpRoot, 'nope'))).toBe(0)
    })
})

describe('sanitizeScenarioKey', () => {
    it('replaces path separators and dots', () => {
        expect(sanitizeScenarioKey('tests/accordion.test.ts')).toMatch(/^tests_accordion_test_ts-[0-9a-f]{8}$/)
    })

    it('keeps apart two keys that sanitize to the same base', () => {
        expect(sanitizeScenarioKey('a/b.ts')).not.toBe(sanitizeScenarioKey('a_b.ts'))
    })

    it('is stable for the same input', () => {
        expect(sanitizeScenarioKey('tests/x.test.ts')).toBe(sanitizeScenarioKey('tests/x.test.ts'))
    })
})
