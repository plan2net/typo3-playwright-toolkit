import { describe, expect, it } from 'vitest'
import { httpSetupCache } from '#src/setup-cache/client.js'
import { configForRun } from '../../helpers.js'

function answering(body: Record<string, unknown>): { fetchImpl: typeof fetch; calls: Request[] } {
    const calls: Request[] = []
    const fetchImpl = (async (url: string, init: RequestInit) => {
        calls.push(new Request(url, init))

        return new Response(JSON.stringify(body), { status: 200 })
    }) as unknown as typeof fetch

    return { fetchImpl, calls }
}

describe('httpSetupCache', () => {
    it('reads the restored state back', async () => {
        const { fetchImpl } = answering({ ok: true, outcome: 'applied', state: { slug: '/from-the-setup' } })

        const restored = await httpSetupCache(configForRun('/tmp/consumer'), { fetchImpl }).restore(
            'ABCD1234EFGH5678',
            'a'.repeat(32),
        )

        expect(restored).toEqual({ outcome: 'applied', state: { slug: '/from-the-setup' }, detail: '' })
    })

    it('sends the setup state to be stored', async () => {
        const { fetchImpl, calls } = answering({ ok: true, outcome: 'stored' })

        const outcome = await httpSetupCache(configForRun('/tmp/consumer'), { fetchImpl }).store(
            'ABCD1234EFGH5678',
            'a'.repeat(32),
            { slug: '/from-the-setup' },
        )

        expect(outcome).toBe('stored')
        expect(calls[0]?.url).toContain('/typo3/test-api/setup-cache/store')
        expect(await calls[0]?.json()).toEqual({
            testId: 'ABCD1234EFGH5678',
            key: 'a'.repeat(32),
            state: { slug: '/from-the-setup' },
            onlyIfPresent: false,
        })
    })

    it('asks for a refresh of an entry that already exists', async () => {
        const { fetchImpl, calls } = answering({ ok: true, outcome: 'absent' })

        await httpSetupCache(configForRun('/tmp/consumer'), { fetchImpl }).store(
            'ABCD1234EFGH5678',
            'a'.repeat(32),
            {},
            true,
        )

        expect(await calls[0]?.json()).toMatchObject({ onlyIfPresent: true })
    })
})
