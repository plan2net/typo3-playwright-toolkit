import * as fs from 'fs'
import * as path from 'path'
import type { ToolkitConfig } from '../config.js'

export const SECRET_HEADER = 'X-Playwright-Toolkit-Secret'
export const SECRET_ENV = 'PLAYWRIGHT_TOOLKIT_SECRET'

/** Where `playwright:prepare` writes it, inside TYPO3's var path. */
export function secretFileFor(config: ToolkitConfig): string {
    return path.join(config.paths.consumerRoot, 'var', 'playwright', 'api-secret')
}

/**
 * Every test endpoint requires this, so failing here is better than failing on
 * each request with an unexplained 401.
 */
export function resolveApiSecret(config: ToolkitConfig): string {
    const fromEnvironment = (process.env[SECRET_ENV] ?? '').trim()
    if (fromEnvironment) {
        return fromEnvironment
    }

    const file = secretFileFor(config)
    const fromFile = fs.existsSync(file) ? fs.readFileSync(file, 'utf-8').trim() : ''
    if (fromFile) {
        return fromFile
    }

    throw new Error(
        `[typo3-playwright-toolkit] No test API secret. Run \`ddev playwright-prepare\` ` +
            `(or \`typo3 playwright:prepare\`) to create ${file}, or set ${SECRET_ENV} ` +
            'to the same value when PHP and Node do not share a filesystem.',
    )
}
