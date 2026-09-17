import { createHash } from 'node:crypto'
import * as fs from 'fs'
import * as path from 'path'

/** Root at the Playwright config's directory, not testDir: builders sit beside it. */
export function cacheKey(suiteRoot: string, scenarioKey: string): string {
    return createHash('sha256')
        .update(`${suiteDigest(suiteRoot)}:${scenarioKey}`)
        .digest('hex')
        .slice(0, 32)
}

function suiteDigest(suiteRoot: string): string {
    const digest = createHash('sha256')

    for (const file of sourceFiles(suiteRoot)) {
        digest.update(path.relative(suiteRoot, file))
        digest.update(fs.readFileSync(file))
    }

    return digest.digest('hex')
}

function sourceFiles(directory: string): string[] {
    const files: string[] = []
    const entries = fs.readdirSync(directory, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))

    for (const entry of entries) {
        const full = path.join(directory, entry.name)

        if (entry.isDirectory()) {
            if ('node_modules' !== entry.name) {
                files.push(...sourceFiles(full))
            }
        } else if (/\.[cm]?[jt]sx?$/.test(entry.name)) {
            files.push(full)
        }
    }

    return files
}
