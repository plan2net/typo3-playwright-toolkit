import { describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as path from 'path'
import { fileURLToPath } from 'url'
import { PROBE_HEADER, TEST_ID_HEADER } from '#src/contract.js'
import { probeSignature, probeSite } from '#src/sites/probe.js'

const fixture = JSON.parse(
    fs.readFileSync(path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../../../contract/site-probe.json'), 'utf8'),
) as { secret: string; testId: string; signature: string; response: Record<string, unknown> }

const shop = { identifier: 'shop', rootPageId: 2573, base: 'https://shop-testing.ddev.site/' }

function answering(status: number, body: string, headers: Record<string, string> = {}): { fetchImpl: typeof fetch; seen: RequestInit[] } {
    const seen: RequestInit[] = []
    const fetchImpl = (async (_url: unknown, init: RequestInit) => {
        seen.push(init)

        return new Response(body, { status, headers })
    }) as unknown as typeof fetch

    return { fetchImpl, seen }
}

function probe(fetchImpl: typeof fetch, site = shop) {
    return probeSite(site, { testId: fixture.testId, secret: fixture.secret, fetchImpl })
}

describe('probeSignature', () => {
    it('signs exactly what the contract fixture records', () => {
        expect(probeSignature(fixture.secret, fixture.testId)).toBe(fixture.signature)
    })
})

describe('probeSite', () => {
    it('accepts the answer the contract fixture records', async () => {
        const { fetchImpl } = answering(200, JSON.stringify(fixture.response))

        expect(await probe(fetchImpl)).toEqual({ ...shop, available: true })
    })

    it('sends the test ID and the signature, and follows no redirect', async () => {
        const { fetchImpl, seen } = answering(200, JSON.stringify(fixture.response))

        await probe(fetchImpl)

        expect(seen[0]).toMatchObject({
            method: 'GET',
            redirect: 'manual',
            headers: { [TEST_ID_HEADER]: fixture.testId, [PROBE_HEADER]: fixture.signature },
        })
    })

    it('names where a redirect goes', async () => {
        const { fetchImpl } = answering(302, '', { location: 'https://login.example/' })

        expect(await probe(fetchImpl)).toEqual({ ...shop, available: false, reason: 'it redirects to https://login.example/' })
    })

    it('reports an ordinary page as no toolkit', async () => {
        const { fetchImpl } = answering(200, '<html>shop</html>')

        expect(await probe(fetchImpl)).toEqual({
            ...shop,
            available: false,
            reason: 'no toolkit answered: the host is not in the Testing context, or it is another installation',
        })
    })

    it('does not take an answer for another test ID as proof', async () => {
        const { fetchImpl } = answering(200, JSON.stringify({ ...fixture.response, testId: 'ZZZZ1234EFGH5678' }))

        expect(await probe(fetchImpl)).toMatchObject({ available: false, reason: expect.stringMatching(/^no toolkit answered/) })
    })

    it('reports another installation when no test database is selected', async () => {
        const { fetchImpl } = answering(200, JSON.stringify({ ...fixture.response, database: null }))

        expect(await probe(fetchImpl)).toMatchObject({
            available: false,
            reason: 'another installation answered: the test database is not selected there',
        })
    })

    it('names the site the host serves instead', async () => {
        const { fetchImpl } = answering(200, JSON.stringify({ ...fixture.response, site: 'corporate' }))

        expect(await probe(fetchImpl)).toMatchObject({ available: false, reason: 'this host serves the site "corporate"' })
    })

    it('reports a server error', async () => {
        const { fetchImpl } = answering(503, JSON.stringify(fixture.response))

        expect(await probe(fetchImpl)).toMatchObject({ available: false, reason: 'it answered 503' })
    })

    it('reports a network error', async () => {
        const fetchImpl = (async () => {
            throw new Error('getaddrinfo ENOTFOUND shop-testing.ddev.site')
        }) as unknown as typeof fetch

        expect(await probe(fetchImpl)).toMatchObject({
            available: false,
            reason: 'getaddrinfo ENOTFOUND shop-testing.ddev.site',
        })
    })
})
