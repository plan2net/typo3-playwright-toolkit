import { deepStrictEqual } from 'node:assert'

/**
 * State is stored as JSON and read back by another process, so a value that
 * changes shape on the way through — a Date becoming a string, undefined
 * disappearing, NaN becoming null — is rejected here rather than surprising the
 * reader later. Comparing the round trip against the original is what catches
 * every such value, including ones JSON gains no opinion about until later.
 */
export function assertJsonSafe(value: unknown, label = 'value'): void {
    try {
        deepStrictEqual(JSON.parse(JSON.stringify(value)) as unknown, value)
    } catch (error) {
        throw new Error(
            `[typo3-playwright-toolkit] ${label} does not survive a JSON round trip, so another ` +
                `process would read back something else:\n${error instanceof Error ? error.message : String(error)}`,
        )
    }
}
