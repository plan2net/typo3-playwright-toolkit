import { randomBytes } from 'node:crypto'

export const RUN_ID_ENV = 'PW_RUN_ID'

/** The run ID becomes a directory name that teardown deletes recursively. */
export const RUN_ID_PATTERN = /^[A-Za-z0-9_-]{8,64}$/

function assertValidRunId(runId: string): string {
    if (!RUN_ID_PATTERN.test(runId)) {
        throw new Error(
            `[typo3-playwright-toolkit] Invalid run ID "${runId}". Expected 8-64 characters of ` +
                `A-Z, a-z, 0-9, "_" or "-". Check ${RUN_ID_ENV}.`,
        )
    }

    return runId
}

/** Written back to the environment so forked workers inherit it. */
export function resolveRunId(explicit?: string): string {
    if (explicit) {
        // Validate before storing, or the next worker inherits a bad value.
        assertValidRunId(explicit)
        process.env[RUN_ID_ENV] = explicit

        return explicit
    }

    const inherited = process.env[RUN_ID_ENV]
    if (inherited) {
        return assertValidRunId(inherited)
    }

    const minted = randomBytes(8).toString('hex')
    process.env[RUN_ID_ENV] = minted

    return minted
}
