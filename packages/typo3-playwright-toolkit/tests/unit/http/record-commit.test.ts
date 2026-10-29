import * as fs from 'fs'
import * as path from 'path'
import { fileURLToPath } from 'url'
import { beforeEach, describe, expect, it } from 'vitest'
import { setToolkitConfig, type ToolkitConfig } from '#src/config.js'
import { translateRecord } from '#src/http/record-commit.js'
import { RecordRefusedError, type FormPoster } from '#src/http/record-edit.js'
import {
    RECORD_DIAGNOSTICS_HEADER,
    SAVED_RECORD_HEADER,
    TRANSLATED_RECORD_HEADER,
} from '#src/contract.js'

const TEST_ID = 'ABCD1234EFGH5678'

function config(): ToolkitConfig {
    return {
        testingURL: 'https://example-testing.test',
        paths: {
            consumerRoot: '/srv/project',
            stateDir: '/srv/project/.test-state',
        },
    }
}

interface Posted {
    url: string
    multipart: Record<string, string>
    headers: Record<string, string>
    maxRedirects?: number
}

function fakePoster(headers: Record<string, string>, status = 200): { poster: FormPoster; posted: Posted[] } {
    const posted: Posted[] = []

    const poster: FormPoster = {
        async post(url, options) {
            posted.push({ url, multipart: options.multipart, headers: options.headers, maxRedirects: options.maxRedirects })

            return {
                status: () => status,
                headers: () => Object.fromEntries(Object.entries(headers).map(([name, value]) => [name.toLowerCase(), value])),
                text: async () => '',
            }
        },
    }

    return { poster, posted }
}

const context = {
    baseUrl: 'https://example-testing.test',
    backendPath: '/typo3',
    testId: TEST_ID,
    routeToken: 'the-route-token',
    commandToken: 'the-command-token',
}

const translated = { [TRANSLATED_RECORD_HEADER]: JSON.stringify({ uid: 3, pid: 2, references: { media: [6, 7] } }) }

beforeEach(() => {
    setToolkitConfig(config())
})

describe('translateRecord', () => {
    it('posts the backend command to its own route with the command token', async () => {
        const { poster, posted } = fakePoster(translated)

        await translateRecord(poster, context, { table: 'pages', uid: 2, language: 1, mode: 'localize' })

        expect(posted[0].url).toBe('https://example-testing.test/typo3/record/commit?token=the-command-token')
        expect(posted[0].multipart).toEqual({ 'cmd[pages][2][localize]': '1' })
        expect(posted[0].maxRedirects).toBe(0)
    })

    it('sends a free copy as copyToLanguage', async () => {
        const { poster, posted } = fakePoster(translated)

        await translateRecord(poster, context, { table: 'tt_content', uid: 5, language: 1, mode: 'copyToLanguage' })

        expect(posted[0].multipart).toEqual({ 'cmd[tt_content][5][copyToLanguage]': '1' })
    })

    it('answers the new uid and its references', async () => {
        const { poster } = fakePoster(translated)

        const translation = await translateRecord(poster, context, { table: 'pages', uid: 2, language: 1, mode: 'localize' })

        expect(translation).toEqual({ uid: 3, pid: 2, references: { media: [6, 7] }, truncated: false })
    })

    it('answers the slug TYPO3 gave a translated page', async () => {
        const { poster } = fakePoster({ ...translated, [SAVED_RECORD_HEADER]: JSON.stringify({ slug: '/de-products' }) })

        const translation = await translateRecord(poster, context, { table: 'pages', uid: 2, language: 1, mode: 'localize' })

        expect(translation.slug).toBe('/de-products')
    })

    it('says when the references did not fit the header', async () => {
        const { poster } = fakePoster({ [TRANSLATED_RECORD_HEADER]: JSON.stringify({ uid: 3, pid: 2, truncated: true }) })

        const translation = await translateRecord(poster, context, { table: 'pages', uid: 2, language: 1, mode: 'localize' })

        expect(translation).toEqual({ uid: 3, pid: 2, references: {}, truncated: true })
    })

    it('fails with TYPO3 reasons when it refused the translation', async () => {
        const { poster } = fakePoster({
            [RECORD_DIAGNOSTICS_HEADER]: JSON.stringify({
                errors: [{ message: 'Localization failed: there already are localizations', table: 'tt_content' }],
                count: 1,
            }),
        })

        await expect(
            translateRecord(poster, context, { table: 'tt_content', uid: 5, language: 1, mode: 'localize' }),
        ).rejects.toThrow(RecordRefusedError)
    })

    it('fails when nothing was translated and TYPO3 gave no reason', async () => {
        const { poster } = fakePoster({})

        await expect(
            translateRecord(poster, context, { table: 'tt_content', uid: 5, language: 1, mode: 'localize' }),
        ).rejects.toThrow(/created no translation of tt_content 5 in language 1/)
    })

    it('names the extension version when the session handed out no command token', async () => {
        const { poster, posted } = fakePoster(translated)

        await expect(
            translateRecord(poster, { ...context, commandToken: '' }, { table: 'pages', uid: 2, language: 1, mode: 'localize' }),
        ).rejects.toThrow(/plan2net\/playwright-toolkit 0\.27\.0/)
        expect(posted).toEqual([])
    })

    it('parses the envelope the extension proves it answers with', async () => {
        const fixturePath = path.resolve(
            path.dirname(fileURLToPath(import.meta.url)),
            '../../../../../contract/translated-record-header.json',
        )
        const envelope = JSON.parse(fs.readFileSync(fixturePath, 'utf-8')) as Record<string, unknown>
        delete envelope._comment
        const { poster } = fakePoster({ [TRANSLATED_RECORD_HEADER]: JSON.stringify(envelope) })

        const translation = await translateRecord(poster, context, { table: 'pages', uid: 2, language: 1, mode: 'localize' })

        expect(translation).toEqual({ uid: envelope.uid, pid: envelope.pid, references: envelope.references, truncated: false })
    })
})
