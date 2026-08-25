import * as fs from 'fs'
import * as path from 'path'

/**
 * Keeps the part of the path that does not exist yet, so a directory that has not
 * been created is checked at the place it will be, not at its closest parent.
 */
function resolveThroughSymlinks(target: string): string {
    let current = path.resolve(target)
    const missing: string[] = []

    for (;;) {
        if (fs.existsSync(current)) {
            return path.join(fs.realpathSync(current), ...missing.reverse())
        }

        const parent = path.dirname(current)
        if (parent === current) {
            return path.join(current, ...missing.reverse())
        }

        missing.push(path.basename(current))
        current = parent
    }
}

function isStrictlyInside(candidate: string, container: string): boolean {
    const relative = path.relative(container, candidate)

    return '' !== relative && !relative.startsWith('..') && !path.isAbsolute(relative)
}

/**
 * Teardown deletes everything inside the directories the consumer names, so a typo
 * deletes whatever that path points at. Only accept a directory inside the project.
 */
export function assertDeletableDirectory(field: string, target: string, consumerRoot: string): void {
    if (!path.isAbsolute(target)) {
        throw new Error(
            `[typo3-playwright-toolkit] paths.${field} must be an absolute path, got "${target}". ` +
                'Teardown deletes inside it, and a relative path resolves against whatever ' +
                'directory the run happens to start in.',
        )
    }

    const root = resolveThroughSymlinks(consumerRoot)
    const resolved = resolveThroughSymlinks(target)

    if (!isStrictlyInside(resolved, root)) {
        throw new Error(
            `[typo3-playwright-toolkit] paths.${field} ("${target}") must be inside ` +
                `paths.consumerRoot ("${consumerRoot}"). It resolves to "${resolved}", and teardown ` +
                'deletes inside it, so pointing it outside the project risks unrelated files.',
        )
    }
}

/** True for a name that can only be a file directly inside a directory. */
export function isPlainSegment(name: string): boolean {
    return '' !== name && name === path.basename(name) && '.' !== name && '..' !== name
}

/** Adds one name to `base`, and refuses anything that points outside it. */
export function safeJoin(base: string, segment: string): string {
    const joined = path.resolve(base, segment)

    if (!isStrictlyInside(joined, path.resolve(base))) {
        throw new Error(`[typo3-playwright-toolkit] "${segment}" escapes ${base}.`)
    }

    return joined
}
