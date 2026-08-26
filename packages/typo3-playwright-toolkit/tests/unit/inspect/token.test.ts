import { describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as path from 'path'
import { fileURLToPath } from 'url'
import {
    mintInspectToken,
    replayInspectUrl,
    INSPECT_TOKEN_LIFETIME_MS,
    REPLAY_SUBJECT,
} from '#src/inspect/token.js'

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

describe('replayInspectUrl', () => {
    it('asks for the replayed database instead of a test one', () => {
        const url = new URL(replayInspectUrl('https://example-testing.test', 'shh', 1_700_000_000_000))

        expect(url.pathname).toBe('/typo3/test-api/inspect')
        expect(url.searchParams.get('replay')).toBe('1')
        expect(url.searchParams.get('id')).toBeNull()
    })

    it('signs the replay subject the extension verifies', () => {
        const url = new URL(replayInspectUrl('https://example-testing.test', 'shh', 1_700_000_000_000))
        const expiresAt = Math.floor((1_700_000_000_000 + INSPECT_TOKEN_LIFETIME_MS) / 1000)

        expect(url.searchParams.get('t')).toBe(mintInspectToken('shh', REPLAY_SUBJECT, expiresAt))
    })

    // The extension verifies this token; both sides are pinned to the fixture.
    it('mints exactly the token the contract fixture records', () => {
        const replayFixture = JSON.parse(
            fs.readFileSync(
                path.resolve(
                    path.dirname(fileURLToPath(import.meta.url)),
                    '../../../../../contract/inspect-replay-token.json',
                ),
                'utf-8',
            ),
        ) as { secret: string; subject: string; expiresAt: number; token: string }

        expect(REPLAY_SUBJECT).toBe(replayFixture.subject)
        expect(mintInspectToken(replayFixture.secret, REPLAY_SUBJECT, replayFixture.expiresAt)).toBe(
            replayFixture.token,
        )
    })
})
