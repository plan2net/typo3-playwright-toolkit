import { spawnSync } from 'node:child_process'
import * as fs from 'node:fs'
import * as path from 'node:path'
import type { ToolkitConfig } from './config.js'

/** First match wins, so npm is the fallback rather than an entry. */
const LOCKFILES: ReadonlyArray<{ lockfile: string; packageManager: string }> = [
    { lockfile: 'pnpm-lock.yaml', packageManager: 'pnpm' },
    { lockfile: 'yarn.lock', packageManager: 'yarn' },
    { lockfile: 'bun.lockb', packageManager: 'bun' },
    { lockfile: 'bun.lock', packageManager: 'bun' },
]

export function resolveBuildCommand(config: ToolkitConfig): string | undefined {
    if (false === config.build) {
        return undefined
    }
    if (config.build) {
        return config.build
    }

    const root = config.paths.consumerRoot
    const manifestPath = path.join(root, 'package.json')

    if (!fs.existsSync(manifestPath)) {
        return undefined
    }

    let manifest: { scripts?: Record<string, string> } | null
    try {
        manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8')) as typeof manifest
    } catch (error) {
        throw new Error(
            [
                `[typo3-playwright-toolkit] Could not read ${manifestPath}, so there is no way`,
                `to tell whether this project builds: ${error instanceof Error ? error.message : String(error)}`,
                '',
                'Fix the file, or say what to do in defineToolkitConfig: build: <your command>,',
                'or build: false for a project that never builds.',
            ].join('\n'),
        )
    }

    if (!manifest?.scripts?.build) {
        return undefined
    }

    const found = LOCKFILES.find((entry) => fs.existsSync(path.join(root, entry.lockfile)))

    return `${found?.packageManager ?? 'npm'} run build`
}

/**
 * `shell: true` is safe here: the command comes from the consumer's own config, never
 * from a request, and `npm run css && npm run js` has to work.
 */
export function runBuild(config: ToolkitConfig): void {
    if ('1' === process.env.PW_SKIP_BUILD) {
        return
    }

    const command = resolveBuildCommand(config)
    if (!command) {
        return
    }

    process.stdout.write(`[toolkit] Building assets: ${command}\n`)

    const { status, error } = spawnSync(command, {
        cwd: config.paths.consumerRoot,
        shell: true,
        stdio: 'inherit',
    })

    if (error) {
        throw new Error(`[typo3-playwright-toolkit] The asset build could not start: ${error.message}`)
    }
    if (0 !== status) {
        throw new Error(
            [
                `[typo3-playwright-toolkit] The asset build failed (exit ${String(status)}): ${command}`,
                '',
                'Fix the build, or run without it: PW_SKIP_BUILD=1, which is what',
                '`ddev playwright test --skip-build` sets. A project that should never',
                'build wants build: false in defineToolkitConfig instead.',
            ].join('\n'),
        )
    }
}
