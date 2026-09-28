import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import { setToolkitConfig, type ToolkitConfig } from '#src/config.js'
import globalSetup from '#src/global-setup.js'
import { configForRun } from '../helpers.js'
import { ensureRunNamespace } from '#src/state/run-namespace.js'
import { readSitesFile, writeSitesFile } from '#src/sites/registry.js'
import { TEST_ID_HEADER } from '#src/contract.js'
import { readAttempts } from '#src/state/attempt-registry.js'

let tmpRoot: string
let config: ToolkitConfig

beforeEach(() => {
    tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-setup-sites-'))
    config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
    ensureRunNamespace(config)
    setToolkitConfig(config)
})

afterEach(() => {
    vi.unstubAllGlobals()
    vi.unstubAllEnvs()
    fs.rmSync(tmpRoot, { recursive: true, force: true })
})

describe('globalSetup writes the site list', () => {
    it('records that discovery was skipped under PW_SKIP_HEALTH', async () => {
        vi.stubEnv('PW_SKIP_HEALTH', '1')

        await globalSetup()

        expect(readSitesFile(config)).toEqual({ skipped: 'PW_SKIP_HEALTH=1' })
    })

    it('records the api of an extension too old to list sites', async () => {
        vi.stubGlobal('fetch', async () => new Response(JSON.stringify({ ok: true, api: 2, checks: {} }), { status: 200 }))

        await globalSetup()

        expect(readSitesFile(config)).toEqual({
            skipped: 'the extension reports api 2; upgrade it to 3 or newer to list its sites',
        })
    })

    it('probes every listed site with the preflight test ID', async () => {
        const probes: Record<string, string>[] = []
        vi.stubGlobal('fetch', async (url: string | URL, init?: { headers?: Record<string, string> }) => {
            if (String(url).startsWith('https://shop-testing.test')) {
                probes.push(init?.headers ?? {})
                const testId = init?.headers?.[TEST_ID_HEADER]

                return new Response(JSON.stringify({ testId, database: `db${testId}`, site: 'shop' }), { status: 200 })
            }

            return new Response(
                JSON.stringify({
                    ok: true,
                    api: 3,
                    checks: {},
                    sites: [{ identifier: 'shop', rootPageId: 7, base: 'https://shop-testing.test/' }],
                }),
                { status: 200 },
            )
        })

        await globalSetup()

        expect(readSitesFile(config)).toEqual({
            sites: [{ identifier: 'shop', rootPageId: 7, base: 'https://shop-testing.test/', available: true }],
        })
        expect(readAttempts(config).map((attempt) => attempt.testId)).toContain(probes[0][TEST_ID_HEADER])
    })

    it('replaces a list an earlier run left behind before it can fail', async () => {
        writeSitesFile(config, { sites: [{ identifier: 'old', rootPageId: 1, base: 'https://old.test/', available: true }] })
        vi.stubGlobal('fetch', async () => new Response(JSON.stringify({ ok: true, api: 0 }), { status: 200 }))

        await expect(globalSetup()).rejects.toThrow(/too old/)

        expect(readSitesFile(config)).toEqual({ skipped: 'site discovery did not finish' })
    })

    it('warns once about each site that failed its probe', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined)
        vi.stubGlobal('fetch', async (url: string | URL) =>
            String(url).startsWith('https://shop.example')
                ? new Response('<html>shop</html>', { status: 200 })
                : new Response(
                    JSON.stringify({
                        ok: true,
                        api: 3,
                        checks: {},
                        sites: [{ identifier: 'shop', rootPageId: 7, base: 'https://shop.example/' }],
                    }),
                    { status: 200 },
                ),
        )

        await globalSetup()

        expect(warn.mock.calls.map(([message]) => String(message))).toEqual([
            '[typo3-playwright-toolkit] The site "shop" (https://shop.example/) is unavailable: no toolkit answered: ' +
                'the host is not in the Testing context, or it is another installation. Scenarios that name it will fail.',
        ])
        warn.mockRestore()
    })
})
