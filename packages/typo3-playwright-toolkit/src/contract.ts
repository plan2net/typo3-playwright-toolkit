import { randomInt } from 'crypto'
import type { ToolkitConfig } from './config.js'
import { SECRET_HEADER, resolveApiSecret } from './http/api-secret.js'

export const TEST_ID_HEADER = 'X-Playwright-Test-Id'
export const TEST_ID_PATTERN = /^[A-Z0-9]{16}$/

const TEST_ID_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
const TEST_ID_LENGTH = 16

export function generateTestId(): string {
    let testId = ''
    for (let index = 0; index < TEST_ID_LENGTH; index++) {
        testId += TEST_ID_ALPHABET.charAt(randomInt(TEST_ID_ALPHABET.length))
    }
    return testId
}

/** Both headers, for one explicit request. */
export function toolkitHeaders(config: ToolkitConfig, testId: string): Record<string, string> {
    return {
        ...browserHeaders(testId),
        [SECRET_HEADER]: resolveApiSecret(config),
    }
}

/** What routing and the request client add. No secret: that stays explicit. */
export function browserHeaders(testId: string): Record<string, string> {
    return { [TEST_ID_HEADER]: testId }
}
