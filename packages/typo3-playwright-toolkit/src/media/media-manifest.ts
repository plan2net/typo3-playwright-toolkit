import * as fs from 'fs'
import * as path from 'path'
import { getToolkitConfig, type ToolkitConfig } from '../config.js'

const cache = new Map<string, Record<string, number>>()

/** Where `playwright:prepare` writes it, inside TYPO3's var path. */
export function mediaManifestFileFor(config: ToolkitConfig): string {
    return path.join(config.paths.consumerRoot, 'var', 'playwright', 'media.json')
}

/** Beside the manifest: each fixture's metadata record in the default language. */
export function metadataManifestFileFor(config: ToolkitConfig): string {
    return path.join(path.dirname(mediaManifestFileFor(config)), 'media-metadata.json')
}

export function resolveMediaUid(config: ToolkitConfig, name: string): number {
    const file = mediaManifestFileFor(config)

    return uidOf(
        read(
            file,
            `[typo3-playwright-toolkit] No media manifest at ${file}.\n` +
                'Run `ddev playwright-prepare` to write it — or, if this project seeds no media,\n' +
                '`withFileReference` needs a numeric sys_file uid rather than a name.',
        ),
        name,
    )
}

export function resolveMetadataUid(config: ToolkitConfig, name: string): number {
    const file = metadataManifestFileFor(config)

    return uidOf(
        read(
            file,
            `[typo3-playwright-toolkit] No media metadata at ${file}.\n` +
                'plan2net/playwright-toolkit 0.27.0 and newer write it: update the extension and run ' +
                '`ddev playwright-prepare`.',
        ),
        name,
    )
}

export function mediaUid(file: string | number): number {
    return typeof file === 'number' ? file : resolveMediaUid(getToolkitConfig(), file)
}

function uidOf(manifest: Record<string, number>, name: string): number {
    const uid = manifest[name]
    if (uid === undefined) {
        throw new Error(
            `[typo3-playwright-toolkit] No fixture named "${name}".\n` +
                `Available: ${Object.keys(manifest).sort().join(', ') || '(none)'}`,
        )
    }

    return uid
}

function read(file: string, missing: string): Record<string, number> {
    const cached = cache.get(file)
    if (undefined !== cached) {
        return cached
    }

    if (!fs.existsSync(file)) {
        throw new Error(missing)
    }

    const manifest = JSON.parse(fs.readFileSync(file, 'utf-8')) as Record<string, number>
    cache.set(file, manifest)

    return manifest
}
