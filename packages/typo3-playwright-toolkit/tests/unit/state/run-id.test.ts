import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { RUN_ID_ENV, resolveRunId } from '#src/state/run-id.js'

let originalRunId: string | undefined

beforeEach(() => {
    originalRunId = process.env[RUN_ID_ENV]
    delete process.env[RUN_ID_ENV]
})

afterEach(() => {
    if (originalRunId === undefined) {
        delete process.env[RUN_ID_ENV]
    } else {
        process.env[RUN_ID_ENV] = originalRunId
    }
})

describe('resolveRunId', () => {
    it('mints a 16-character hex id when nothing is set', () => {
        expect(resolveRunId()).toMatch(/^[0-9a-f]{16}$/)
    })

    it('exports the minted id so forked workers inherit it', () => {
        const runId = resolveRunId()

        expect(process.env[RUN_ID_ENV]).toBe(runId)
    })

    it('returns the same id on repeated calls in one process', () => {
        expect(resolveRunId()).toBe(resolveRunId())
    })

    it('reuses an id inherited from the environment', () => {
        process.env[RUN_ID_ENV] = 'abcdef0123456789'

        expect(resolveRunId()).toBe('abcdef0123456789')
    })

    it('lets an explicit id win over the environment', () => {
        process.env[RUN_ID_ENV] = 'abcdef0123456789'

        expect(resolveRunId('0123456789abcdef')).toBe('0123456789abcdef')
    })

    it('exports an explicit id so staged invocations agree', () => {
        resolveRunId('0123456789abcdef')

        expect(process.env[RUN_ID_ENV]).toBe('0123456789abcdef')
    })

    it('ignores an empty environment value', () => {
        process.env[RUN_ID_ENV] = ''

        expect(resolveRunId()).toMatch(/^[0-9a-f]{16}$/)
    })

    it('accepts a readable CI-style id', () => {
        expect(resolveRunId('ci-build-1234')).toBe('ci-build-1234')
    })
})

// The run ID becomes a directory name that teardown removes recursively, so a
// value able to escape that directory has to be refused before it is ever used.
describe('resolveRunId — refuses ids that could escape the state directory', () => {
    it('refuses a parent-directory traversal from the environment', () => {
        process.env[RUN_ID_ENV] = '../..'

        expect(() => resolveRunId()).toThrow(/Invalid run ID/)
    })

    it('refuses an explicit parent-directory traversal', () => {
        expect(() => resolveRunId('../../..')).toThrow(/Invalid run ID/)
    })

    it('refuses a value containing a path separator', () => {
        expect(() => resolveRunId('runs/../../etc')).toThrow(/Invalid run ID/)
    })

    it('refuses an absolute path', () => {
        expect(() => resolveRunId('/etc/passwd')).toThrow(/Invalid run ID/)
    })

    it('refuses a single dot and a bare double dot', () => {
        expect(() => resolveRunId('.')).toThrow(/Invalid run ID/)
        expect(() => resolveRunId('..')).toThrow(/Invalid run ID/)
    })

    it('refuses a value too short to be a real run id', () => {
        expect(() => resolveRunId('abc')).toThrow(/Invalid run ID/)
    })

    it('refuses a value carrying a shell metacharacter', () => {
        expect(() => resolveRunId('run$(whoami)')).toThrow(/Invalid run ID/)
    })

    it('names the environment variable so the fix is obvious', () => {
        expect(() => resolveRunId('..')).toThrow(/PW_RUN_ID/)
    })

    it('does not store a refused value in the environment', () => {
        expect(() => resolveRunId('../..')).toThrow()

        expect(process.env[RUN_ID_ENV]).toBeUndefined()
    })
})
