import { createHmac } from 'node:crypto'

const PURPOSE = 'inspect'

/** Long enough to pick a link from the list, short enough that a pasted one dies. */
export const INSPECT_TOKEN_LIFETIME_MS = 900_000

export function mintInspectToken(secret: string, testId: string, expiresAt: number): string {
    const signature = createHmac('sha256', secret).update(`${PURPOSE}:${testId}:${expiresAt}`).digest('hex')

    return `${expiresAt}.${signature}`
}

export function inspectUrl(testingURL: string, secret: string, testId: string, now: number): string {
    const expiresAt = Math.floor((now + INSPECT_TOKEN_LIFETIME_MS) / 1000)

    return (
        `${testingURL}/typo3/test-api/inspect` +
        `?id=${encodeURIComponent(testId)}` +
        `&t=${encodeURIComponent(mintInspectToken(secret, testId, expiresAt))}`
    )
}
