import { beforeEach, describe, expect, it } from 'vitest'
import { setToolkitConfig } from '#src/config.js'
import { SECRET_HEADER } from '#src/http/api-secret.js'
import { TEST_ID_HEADER } from '#src/contract.js'
import { saveSiteSettings, type JsonPoster } from '#src/http/site-settings.js'

const TEST_ID = 'ABCD1234EFGH5678'
const context = { baseUrl: 'https://example-testing.test', backendPath: '/typo3', testId: TEST_ID }

interface Posted {
    url: string
    headers: Record<string, string>
    data: unknown
}

function fakePoster(status = 200, body = '{"ok":true}'): { poster: JsonPoster; posted: Posted[] } {
    const posted: Posted[] = []

    return {
        posted,
        poster: {
            async post(url, options) {
                posted.push({ url, headers: options.headers, data: options.data })

                return { status: () => status, text: async () => body }
            },
        },
    }
}

describe('saveSiteSettings', () => {
    beforeEach(() => {
        process.env.PLAYWRIGHT_TOOLKIT_SECRET = 'secret'
        setToolkitConfig({
            testingURL: 'https://example-testing.test',
            paths: { consumerRoot: '/srv/project', stateDir: '/srv/project/.test-state' },
        })
    })

    it('posts the site and its values with the test ID and the secret', async () => {
        const { poster, posted } = fakePoster()

        await saveSiteSettings(poster, context, 'shop', { 'theme.look': 'wild' })

        expect(posted).toEqual([
            {
                url: 'https://example-testing.test/typo3/test-api/site-settings',
                headers: { [TEST_ID_HEADER]: TEST_ID, [SECRET_HEADER]: 'secret' },
                data: { site: 'shop', settings: { 'theme.look': 'wild' } },
            },
        ])
    })

    it('refuses a scenario whose site was not discovered', async () => {
        const { poster, posted } = fakePoster()

        await expect(saveSiteSettings(poster, context, '', { 'theme.look': 'wild' })).rejects.toThrow(
            'Site settings need the site TYPO3 resolves',
        )
        expect(posted).toEqual([])
    })

    it('fails with the reason the extension gives', async () => {
        const { poster } = fakePoster(400, '{"success":false,"error":"Expected a \\"site\\" identifier"}')

        await expect(saveSiteSettings(poster, context, 'shop', {})).rejects.toThrow(
            'Site settings for "shop" were not saved (status 400): Expected a "site" identifier',
        )
    })
})
