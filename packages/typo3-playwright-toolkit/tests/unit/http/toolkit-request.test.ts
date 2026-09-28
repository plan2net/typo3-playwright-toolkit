import { describe, expect, it } from 'vitest'
import type { APIRequestContext } from '@playwright/test'
import { SECRET_HEADER } from '#src/http/api-secret.js'
import { TEST_ID_HEADER } from '#src/contract.js'
import { toolkitRequest } from '#src/http/toolkit-request.js'
import type { ToolkitConfig } from '#src/config.js'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import { configForRun } from '../../helpers.js'
import { ensureRunNamespace } from '#src/state/run-namespace.js'
import { writeSitesFile } from '#src/sites/registry.js'

const TEST_ID = 'ABCD1234EFGH5678'

function config(): ToolkitConfig {
    return {
        testingURL: 'https://site-testing.test',
        contentTypes: {},
        paths: {
            consumerRoot: '/srv/project',
            stateDir: '/srv/project/.test-state',
            sessionDir: '/srv/project/var/session',
        },
    }
}

interface Call {
    url: string
    options?: { headers?: Record<string, string> }
}

function fakeRequest(calls: Call[]): APIRequestContext {
    const record = (url: string, options?: Call['options']): Promise<string> => {
        calls.push({ url, options })

        return Promise.resolve('answered')
    }

    return {
        get: record,
        post: record,
        put: record,
        patch: record,
        head: record,
        delete: record,
        fetch: record,
        storageState: () => Promise.resolve({ cookies: [], origins: [] }),
    } as unknown as APIRequestContext
}

function headersOf(calls: Call[]): Record<string, string> {
    return calls[0]?.options?.headers ?? {}
}

describe('the request client a scenario hands out', () => {
    it('gives the site under test its test id', async () => {
        const calls: Call[] = []

        await toolkitRequest(fakeRequest(calls), config(), TEST_ID).get('https://site-testing.test/api')

        expect(headersOf(calls)[TEST_ID_HEADER]).toBe(TEST_ID)
    })

    it('treats a relative url as the site under test, the way baseURL resolves it', async () => {
        const calls: Call[] = []

        await toolkitRequest(fakeRequest(calls), config(), TEST_ID).post('/typo3/record/edit')

        expect(headersOf(calls)[TEST_ID_HEADER]).toBe(TEST_ID)
    })

    it('does not add the test id to a third party', async () => {
        const calls: Call[] = []

        await toolkitRequest(fakeRequest(calls), config(), TEST_ID).get('https://cdn.example.test/app.js')

        expect(headersOf(calls)[TEST_ID_HEADER]).toBeUndefined()
    })

    // No scheme, but not relative either: the site's scheme plus another host.
    it('does not add the test id to a protocol-relative third party', async () => {
        const calls: Call[] = []

        await toolkitRequest(fakeRequest(calls), config(), TEST_ID).get('//cdn.example.test/app.js')

        expect(headersOf(calls)[TEST_ID_HEADER]).toBeUndefined()
    })

    // A url we cannot read is not known to be ours; rejecting it is Playwright's job.
    it('counts a url it cannot parse as off-site', async () => {
        const calls: Call[] = []

        await toolkitRequest(fakeRequest(calls), config(), TEST_ID).get('http://', {
            headers: { [SECRET_HEADER]: 'the-secret' },
        })

        expect(headersOf(calls)).toEqual({})
    })

    it('takes both toolkit headers off a third party the caller addressed itself', async () => {
        const calls: Call[] = []

        await toolkitRequest(fakeRequest(calls), config(), TEST_ID).get('https://cdn.example.test/app.js', {
            headers: { accept: '*/*', [TEST_ID_HEADER]: TEST_ID, [SECRET_HEADER]: 'the-secret' },
        })

        expect(headersOf(calls)).toEqual({ accept: '*/*' })
    })

    it('strips them whatever case the caller wrote them in', async () => {
        const calls: Call[] = []

        await toolkitRequest(fakeRequest(calls), config(), TEST_ID).get('https://cdn.example.test/app.js', {
            headers: { 'X-PLAYWRIGHT-TEST-ID': TEST_ID },
        })

        expect(headersOf(calls)).toEqual({})
    })

    it('keeps the caller options it does not touch', async () => {
        const calls: Call[] = []

        await toolkitRequest(fakeRequest(calls), config(), TEST_ID).post('/api', {
            data: { a: 1 },
            headers: { accept: 'application/json' },
        })

        expect(headersOf(calls).accept).toBe('application/json')
        expect((calls[0]?.options as { data?: unknown })?.data).toEqual({ a: 1 })
    })

    it('adds no header at all when the scenario has no test id', async () => {
        const calls: Call[] = []

        await toolkitRequest(fakeRequest(calls), config(), '').get('https://site-testing.test/api')

        expect(headersOf(calls)[TEST_ID_HEADER]).toBeUndefined()
    })

    it('leaves the members that send nothing alone', async () => {
        const wrapped = toolkitRequest(fakeRequest([]), config(), TEST_ID)

        await expect(wrapped.storageState()).resolves.toEqual({ cookies: [], origins: [] })
    })

    it('covers every verb, not only the one the builders happen to use', async () => {
        const calls: Call[] = []
        const wrapped = toolkitRequest(fakeRequest(calls), config(), TEST_ID)

        await wrapped.put('/a')
        await wrapped.patch('/b')
        await wrapped.delete('/c')
        await wrapped.head('/d')
        await wrapped.fetch('/e')

        expect(calls).toHaveLength(5)
        for (const call of calls) {
            expect(call.options?.headers?.[TEST_ID_HEADER]).toBe(TEST_ID)
        }
    })
})

describe('a scenario site', () => {
    const shop = { identifier: 'shop', rootPageId: 7, base: 'https://shop-testing.test/', origin: 'https://shop-testing.test' }

    it('resolves a relative URL against the site and sends it absolute', async () => {
        const calls: Call[] = []

        await toolkitRequest(fakeRequest(calls), config(), TEST_ID, shop).get('/cart')

        expect(calls[0].url).toBe('https://shop-testing.test/cart')
    })

    it('gives a site of this run the test ID', async () => {
        const root = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-request-'))
        const run = configForRun(root, 'aaaaaaaaaaaaaaaa')
        ensureRunNamespace(run)
        writeSitesFile(run, { sites: [{ ...shop, available: true }] })
        const calls: Call[] = []

        await toolkitRequest(fakeRequest(calls), run, TEST_ID, shop).get('/cart')

        fs.rmSync(root, { recursive: true, force: true })
        expect(headersOf(calls)[TEST_ID_HEADER]).toBe(TEST_ID)
    })
})
