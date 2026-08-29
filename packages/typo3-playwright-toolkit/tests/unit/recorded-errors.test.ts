import { describe, expect, it } from 'vitest'
import { fetchRecordedErrors, formatRecordedErrors } from '#src/http/recorded-errors.js'
import type { ToolkitConfig } from '#src/config.js'

const config = {
    testingURL: 'https://example-testing.ddev.site',
    paths: { consumerRoot: '/app', stateDir: '/app/.test-state', sessionDir: '/app/var/session' },
} as ToolkitConfig

describe('fetchRecordedErrors', () => {
    it('asks the errors endpoint for one test and returns what it recorded', async () => {
        const seen: Array<{ url: string; headers: Record<string, string> }> = []
        const fetchImpl = (async (url: string, init: RequestInit) => {
            seen.push({ url, headers: init.headers as Record<string, string> })

            return {
                ok: true,
                status: 200,
                json: async () => ({
                    success: true,
                    testId: 'K7F2QX9M4TB6WZ1P',
                    truncated: false,
                    errors: [{ uid: 1, source: 'php', at: '2026-08-29T09:14:21+00:00', message: 'boom', count: 2 }],
                }),
            }
        }) as unknown as typeof fetch

        const recorded = await fetchRecordedErrors(config, 'K7F2QX9M4TB6WZ1P', {
            fetchImpl,
            secret: 'the-secret',
        })

        expect(seen[0].url).toBe(
            'https://example-testing.ddev.site/typo3/test-api/errors?id=K7F2QX9M4TB6WZ1P',
        )
        expect(seen[0].headers['X-Playwright-Test-Id']).toBeUndefined()
        expect(recorded?.errors).toEqual([
            { uid: 1, source: 'php', at: '2026-08-29T09:14:21+00:00', message: 'boom', count: 2 },
        ])
    })

    const failingFetch: Array<[string, typeof fetch]> = [
        ['the request fails', () => Promise.reject(new Error('ECONNREFUSED'))],
        ['the answer is not 200', async () => ({ ok: false, status: 500, json: async () => ({}) })],
        ['the body is not json', async () => ({ ok: true, status: 200, json: () => Promise.reject(new SyntaxError('nope')) })],
    ] as unknown as Array<[string, typeof fetch]>

    it.each(failingFetch)('gives up quietly when %s', async (_case, fetchImpl) => {
        await expect(
            fetchRecordedErrors(config, 'K7F2QX9M4TB6WZ1P', { fetchImpl, secret: 's' }),
        ).resolves.toBeUndefined()
    })
})

describe('formatRecordedErrors', () => {
    it('names the scenario, counts repeats and keeps the origin of each entry', () => {
        const summary = formatRecordedErrors('pages/teaser', {
            testId: 'K7F2QX9M4TB6WZ1P',
            truncated: false,
            errors: [
                {
                    uid: 1,
                    source: 'php',
                    at: '2026-08-29T09:14:21+00:00',
                    level: 'error',
                    message: 'No page configured for type=99999.',
                    count: 4,
                },
                {
                    uid: 2,
                    source: 'datahandler',
                    at: '2026-08-29T09:14:22+00:00',
                    message: 'Attempt to insert a record on page ...',
                    table: 'sys_file_reference',
                    count: 1,
                },
            ],
        })

        expect(summary).toBe(
            '[typo3-playwright-toolkit] TYPO3 recorded 2 errors for scenario "pages/teaser" ' +
                '(test K7F2QX9M4TB6WZ1P):\n' +
                '\n' +
                '  1. PHP error (4x)\n' +
                '     No page configured for type=99999.\n' +
                '\n' +
                '  2. DataHandler sys_file_reference\n' +
                '     Attempt to insert a record on page ...',
        )
    })
})
