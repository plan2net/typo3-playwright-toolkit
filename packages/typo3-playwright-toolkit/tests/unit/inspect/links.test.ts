import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import { findStateDir, inspectLinks } from '#src/inspect/links.js'

let root: string

function writeRun(runId: string, testingURL: string, attempts: { key: string; testId: string }[]): string {
    const runDir = path.join(root, '.test-state', 'runs', runId)
    fs.mkdirSync(runDir, { recursive: true })
    fs.writeFileSync(path.join(runDir, 'meta.json'), JSON.stringify({ runId, testingURL }))
    fs.writeFileSync(
        path.join(runDir, 'attempts.jsonl'),
        attempts.map((a) => JSON.stringify({ type: 'attempt', ...a, attempt: 1, nonce: 'n' })).join('\n'),
    )

    return runDir
}

beforeEach(() => {
    root = fs.mkdtempSync(path.join(os.tmpdir(), 'inspect-links-'))
})

afterEach(() => {
    fs.rmSync(root, { recursive: true, force: true })
})

describe('findStateDir', () => {
    it('finds the state directory from a directory below it', () => {
        fs.mkdirSync(path.join(root, '.test-state', 'runs'), { recursive: true })
        const deep = path.join(root, 'tests', 'playwright')
        fs.mkdirSync(deep, { recursive: true })

        expect(findStateDir(deep)).toBe(path.join(root, '.test-state'))
    })

    it('is undefined when there is none', () => {
        expect(findStateDir(root)).toBeUndefined()
    })

    it('walks past a .test-state that holds no runs', () => {
        fs.mkdirSync(path.join(root, '.test-state', 'runs'), { recursive: true })
        const deep = path.join(root, 'tests', 'playwright')
        fs.mkdirSync(path.join(deep, '.test-state'), { recursive: true })

        expect(findStateDir(deep)).toBe(path.join(root, '.test-state'))
    })

    it('is undefined when the only candidate holds no runs', () => {
        const deep = path.join(root, 'tests', 'playwright')
        fs.mkdirSync(path.join(deep, '.test-state', '__statuses'), { recursive: true })

        expect(findStateDir(deep)).toBeUndefined()
    })
})

describe('inspectLinks', () => {
    it('builds one link per recorded database, newest run first', () => {
        writeRun('bbbbbbbbbbbbbbbb', 'https://site-testing.test', [
            { key: 'checkout', testId: 'AAAAAAAAAAAAAAA1' },
            { key: 'search', testId: 'BBBBBBBBBBBBBBB1' },
        ])

        const links = inspectLinks(path.join(root, '.test-state'), 'the-secret', 0)

        expect(links).toHaveLength(2)
        expect(links[0]).toMatchObject({ runId: 'bbbbbbbbbbbbbbbb', key: 'checkout', testId: 'AAAAAAAAAAAAAAA1' })
        expect(links[0].url).toContain('https://site-testing.test/typo3/test-api/inspect?id=AAAAAAAAAAAAAAA1&t=')
    })

    // The preflight probe registers an id but never builds a page worth opening.
    it('leaves the preflight probe out', () => {
        writeRun('bbbbbbbbbbbbbbbb', 'https://site-testing.test', [
            { key: 'preflight', testId: 'AAAAAAAAAAAAAAA1' },
            { key: 'checkout', testId: 'BBBBBBBBBBBBBBB1' },
        ])

        const links = inspectLinks(path.join(root, '.test-state'), 'the-secret', 0)

        expect(links.map((link) => link.key)).toEqual(['checkout'])
    })

    it('is empty when a run recorded no testing url', () => {
        const runDir = writeRun('bbbbbbbbbbbbbbbb', 'https://site-testing.test', [
            { key: 'checkout', testId: 'AAAAAAAAAAAAAAA1' },
        ])
        fs.writeFileSync(path.join(runDir, 'meta.json'), JSON.stringify({ runId: 'bbbbbbbbbbbbbbbb' }))

        expect(inspectLinks(path.join(root, '.test-state'), 'the-secret', 0)).toEqual([])
    })

    it('is empty when nothing ran yet', () => {
        fs.mkdirSync(path.join(root, '.test-state'), { recursive: true })

        expect(inspectLinks(path.join(root, '.test-state'), 'the-secret', 0)).toEqual([])
    })
})
