import { describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import { assertDeletableDirectory, safeJoin } from '#src/state/safe-paths.js'

function realTempRoot(): string {
    return fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-paths-')))
}

describe('assertDeletableDirectory', () => {
    it('accepts a directory below the consumer root', () => {
        const root = realTempRoot()

        expect(() => assertDeletableDirectory('stateDir', path.join(root, '.test-state'), root)).not.toThrow()
    })

    it('accepts a directory that does not exist yet', () => {
        const root = realTempRoot()

        expect(() =>
            assertDeletableDirectory('stateDir', path.join(root, 'var', 'deep', 'state'), root),
        ).not.toThrow()
    })

    it('rejects a relative path, which resolves against an unknown cwd', () => {
        const root = realTempRoot()

        expect(() => assertDeletableDirectory('stateDir', '.test-state', root)).toThrow(/absolute/)
    })

    // Teardown removes the contents of both directories, so the consumer root
    // itself would take the project's own files with it.
    it('rejects the consumer root itself', () => {
        const root = realTempRoot()

        expect(() => assertDeletableDirectory('stateDir', root, root)).toThrow(/inside/)
    })

    it('rejects a filesystem root', () => {
        const root = realTempRoot()

        expect(() => assertDeletableDirectory('sessionDir', path.parse(root).root, root)).toThrow(/inside/)
    })

    it('rejects a path that escapes the consumer root', () => {
        const root = realTempRoot()

        expect(() => assertDeletableDirectory('stateDir', path.join(root, '..', 'elsewhere'), root)).toThrow(
            /inside/,
        )
    })

    // A symlink pointing out of the project would otherwise pass the string
    // comparison and delete whatever it targets.
    it('rejects a symlink whose target escapes the consumer root', () => {
        const root = realTempRoot()
        const outside = realTempRoot()
        const link = path.join(root, 'state-link')
        fs.symlinkSync(outside, link)

        expect(() => assertDeletableDirectory('stateDir', link, root)).toThrow(/inside/)
    })

    it('names the offending field so the consumer knows which one to fix', () => {
        const root = realTempRoot()

        expect(() => assertDeletableDirectory('sessionDir', '/', root)).toThrow(/sessionDir/)
    })
})

describe('safeJoin', () => {
    it('joins a plain segment', () => {
        expect(safeJoin('/base/dir', 'file.json')).toBe(path.join('/base/dir', 'file.json'))
    })

    it('refuses a segment that climbs out of the base', () => {
        expect(() => safeJoin('/base/dir', '../../etc/passwd')).toThrow(/escapes/)
    })

    it('refuses an absolute segment, which would replace the base entirely', () => {
        expect(() => safeJoin('/base/dir', '/etc/passwd')).toThrow(/escapes/)
    })
})
