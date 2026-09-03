import { describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as path from 'path'

const SRC = path.join(path.dirname(new URL(import.meta.url).pathname), '../../src')

/** Every shipped module, so a rule here cannot be dodged by adding a directory. */
function shippedModules(): { name: string; relative: string; source: string }[] {
    const found: { name: string; relative: string; source: string }[] = []

    const walk = (dir: string): void => {
        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
            const full = path.join(dir, entry.name)
            if (entry.isDirectory()) {
                walk(full)
                continue
            }
            if (!entry.name.endsWith('.ts') || entry.name.endsWith('.test.ts')) {
                continue
            }
            found.push({
                name: entry.name,
                relative: path.relative(SRC, full),
                source: fs.readFileSync(full, 'utf8'),
            })
        }
    }
    walk(SRC)

    return found
}

/**
 * Asserted on the source because ESM exports cannot be spied on. The next rule of
 * this kind belongs here rather than in the test of whichever module it protects.
 */
describe('what src/ is allowed to depend on', () => {
    it('finds the modules it claims to check', () => {
        expect(shippedModules().map((module) => module.name)).toContain('global-teardown.ts')
    })

    // The toolkit names test ids and the extension does the database work, so there
    // is no client binary, credential or engine knowledge on this side.
    it('speaks to no database client', () => {
        const offenders = shippedModules()
            .filter((module) => /\bpsql\b|\bmysqld?\b/.test(module.source))
            .map((module) => module.relative)

        expect(offenders).toEqual([])
    })

    // build.ts runs the consumer's build; nothing else needs to start a process.
    it('spawns a process from build.ts alone', () => {
        const offenders = shippedModules()
            .filter((module) => 'build.ts' !== module.name && /child_process/.test(module.source))
            .map((module) => module.relative)

        expect(offenders).toEqual([])
    })
})
