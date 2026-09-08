import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import type { ToolkitConfig } from '#src/config.js'
import { mediaManifestFileFor, resolveMediaUid } from '#src/media/media-manifest.js'

let root: string

function configFor(consumerRoot: string): ToolkitConfig {
    return {
        testingURL: 'https://example-testing.test',
        contentTypes: {},
        paths: {
            consumerRoot,
            stateDir: path.join(consumerRoot, '.test-state'),
            sessionDir: path.join(consumerRoot, 'var/session'),
        },
    }
}

function writeManifest(consumerRoot: string, uidsByName: Record<string, number>): void {
    const file = mediaManifestFileFor(configFor(consumerRoot))
    fs.mkdirSync(path.dirname(file), { recursive: true })
    fs.writeFileSync(file, JSON.stringify(uidsByName))
}

beforeEach(() => {
    root = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-media-')))
})

afterEach(() => {
    fs.rmSync(root, { recursive: true, force: true })
})

describe('resolveMediaUid', () => {
    it('resolves a name to the uid the manifest carries', () => {
        writeManifest(root, { 'hero.png': 900001, 'gallery/lawn-01.jpg': 900002 })

        expect(resolveMediaUid(configFor(root), 'gallery/lawn-01.jpg')).toBe(900002)
    })

    it('lists every available name when one does not match', () => {
        writeManifest(root, { 'portrait.jpg': 900002, 'hero.png': 900001, 'gallery/lawn-01.jpg': 900003 })

        expect(() => resolveMediaUid(configFor(root), 'hero.jpg')).toThrow(
            /No fixture named "hero\.jpg"[\s\S]*gallery\/lawn-01\.jpg, hero\.png, portrait\.jpg/,
        )
    })

    it('names the file to create and the numeric alternative when there is no manifest', () => {
        expect(() => resolveMediaUid(configFor(root), 'hero.png')).toThrow(
            /No media manifest at[\s\S]*playwright-prepare[\s\S]*numeric sys_file uid/,
        )
    })
})

// The two packages have separate test suites and can drift apart without either
// noticing. This reads the same fixture the extension asserts its own writer
// against, so a change to the manifest shape fails on both sides.
describe('the contract fixture', () => {
    it('resolves the names the extension writes', () => {
        const repoRoot = path.resolve(new URL(import.meta.url).pathname, '../../../../../..')
        const file = mediaManifestFileFor(configFor(root))
        fs.mkdirSync(path.dirname(file), { recursive: true })
        fs.copyFileSync(path.join(repoRoot, 'contract', 'media-manifest.json'), file)

        expect(resolveMediaUid(configFor(root), 'hero.png')).toBe(900001)
        expect(resolveMediaUid(configFor(root), 'gallery/lawn-01.jpg')).toBe(900002)
    })
})
