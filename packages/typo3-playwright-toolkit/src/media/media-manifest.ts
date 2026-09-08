import * as fs from 'fs'
import * as path from 'path'
import { getToolkitConfig, type ToolkitConfig } from '../config.js'

const cache = new Map<string, Record<string, number>>()

/** Where `playwright:prepare` writes it, inside TYPO3's var path. */
export function mediaManifestFileFor(config: ToolkitConfig): string {
    return path.join(config.paths.consumerRoot, 'var', 'playwright', 'media.json')
}

export function resolveMediaUid(config: ToolkitConfig, name: string): number {
    const file = mediaManifestFileFor(config)
    const manifest = cache.get(file) ?? read(file)
    cache.set(file, manifest)

    const uid = manifest[name]
    if (uid === undefined) {
        throw new Error(
            `[typo3-playwright-toolkit] No fixture named "${name}".\n` +
                `Available: ${Object.keys(manifest).sort().join(', ') || '(none)'}`,
        )
    }

    return uid
}

export function mediaUid(file: string | number): number {
    return typeof file === 'number' ? file : resolveMediaUid(getToolkitConfig(), file)
}

function read(file: string): Record<string, number> {
    if (!fs.existsSync(file)) {
        throw new Error(
            `[typo3-playwright-toolkit] No media manifest at ${file}.\n` +
                'Run `ddev playwright-prepare` to write it — or, if this project seeds no media,\n' +
                '`withFileReference` needs a numeric sys_file uid rather than a name.',
        )
    }

    return JSON.parse(fs.readFileSync(file, 'utf-8')) as Record<string, number>
}
