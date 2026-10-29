import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import { setToolkitConfig } from '#src/config.js'
import { FileMetadataBuilder } from '#src/builders/file-metadata-builder.js'
import { metadataManifestFileFor } from '#src/media/media-manifest.js'
import { TRANSLATED_RECORD_HEADER } from '#src/contract.js'

const TEST_ID = 'ABCD1234EFGH5678'
const CONTEXT = { routeToken: 'edit-token', commandToken: 'command-token' }

let root: string

function backend() {
    const posted: Array<{ url: string; fields: Record<string, string> }> = []

    const page = {
        url: () => 'https://example-testing.test/typo3/module/web/layout',
        context: () => ({ testId: TEST_ID }),
        testId: TEST_ID,
        request: {
            async post(url: string, options: { multipart: Record<string, string> }) {
                posted.push({ url, fields: options.multipart })

                if (url.includes('/record/commit')) {
                    return {
                        status: () => 200,
                        headers: () => ({
                            [TRANSLATED_RECORD_HEADER.toLowerCase()]: JSON.stringify({ uid: 57, pid: 0, references: {} }),
                        }),
                        text: async () => '',
                    }
                }

                const record = decodeURIComponent(url).match(/edit\[(\w+)\]\[(\d+)\]/)
                return {
                    status: () => 302,
                    headers: () => ({ location: `/typo3/record/edit?edit[${record?.[1]}][${record?.[2]}]=edit` }),
                    text: async () => '',
                }
            },
        },
    }

    return { posted, page: page as unknown as ConstructorParameters<typeof FileMetadataBuilder>[0] }
}

beforeEach(() => {
    root = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-file-metadata-')))
    const config = {
        testingURL: 'https://example-testing.test',
        contentTypes: {},
        paths: { consumerRoot: root, stateDir: path.join(root, '.test-state') },
    }
    const file = metadataManifestFileFor(config)
    fs.mkdirSync(path.dirname(file), { recursive: true })
    fs.writeFileSync(file, JSON.stringify({ 'hero.png': 41 }))
    setToolkitConfig(config)
})

afterEach(() => {
    fs.rmSync(root, { recursive: true, force: true })
})

describe('the metadata of a media fixture', () => {
    it('is changed in the default language on the record prepare wrote', async () => {
        const { posted, page } = backend()

        const saved = await new FileMetadataBuilder(page, 'hero.png', CONTEXT).withField('alternative', 'A lawn').create()

        expect(posted[0].url).toContain('edit%5Bsys_file_metadata%5D%5B41%5D=edit')
        expect(posted[0].fields).toMatchObject({ 'data[sys_file_metadata][41][alternative]': 'A lawn' })
        expect(saved).toEqual({ id: '41' })
    })

    it('is translated the way the backend does, then filled', async () => {
        const { posted, page } = backend()

        const translated = await new FileMetadataBuilder(page, 'hero.png', CONTEXT)
            .translate(1)
            .withField('alternative', 'Ein Rasen')
            .create()

        expect(posted[0].fields).toEqual({ 'cmd[sys_file_metadata][41][localize]': '1' })
        expect(posted[1].fields).toMatchObject({ 'data[sys_file_metadata][57][alternative]': 'Ein Rasen' })
        expect(translated).toEqual({ id: '57' })
    })

    it('refuses a number, which names a file and not its metadata', () => {
        const { page } = backend()

        expect(() => new FileMetadataBuilder(page, 900001 as unknown as string, CONTEXT)).toThrow(
            /name of a media fixture/,
        )
    })
})
