import { describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as path from 'path'
import { fileURLToPath } from 'url'
import { mintInspectToken, INSPECT_TOKEN_LIFETIME_MS } from '#src/inspect/token.js'

const fixture = JSON.parse(
    fs.readFileSync(
        path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../../../contract/inspect-token.json'),
        'utf-8',
    ),
) as { secret: string; testId: string; expiresAt: number; token: string }

describe('mintInspectToken', () => {
    // The extension verifies this token. Both sides are pinned to the fixture, so
    // a change to either signature shape fails here and in InspectTokenTest.
    it('mints exactly the token the contract fixture records', () => {
        expect(mintInspectToken(fixture.secret, fixture.testId, fixture.expiresAt)).toBe(fixture.token)
    })

    it('signs the expiry, so two expiries never share a signature', () => {
        const one = mintInspectToken(fixture.secret, fixture.testId, fixture.expiresAt)
        const two = mintInspectToken(fixture.secret, fixture.testId, fixture.expiresAt + 1)

        expect(one).not.toBe(two)
    })

    it('signs the test id, so two tests never share a signature', () => {
        const one = mintInspectToken(fixture.secret, fixture.testId, fixture.expiresAt)
        const two = mintInspectToken(fixture.secret, 'ZZZZ9999ZZZZ9999', fixture.expiresAt)

        expect(one).not.toBe(two)
    })

    it('outlives reading the list and picking a database from it', () => {
        expect(INSPECT_TOKEN_LIFETIME_MS).toBe(900_000)
    })
})
