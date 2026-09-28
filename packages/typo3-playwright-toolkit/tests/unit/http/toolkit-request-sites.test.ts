import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import type { APIRequestContext } from '@playwright/test'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import { TEST_ID_HEADER } from '#src/contract.js'
import { toolkitRequest } from '#src/http/toolkit-request.js'
import type { ToolkitConfig } from '#src/config.js'
import { ensureRunNamespace } from '#src/state/run-namespace.js'
import { writeSitesFile } from '#src/sites/registry.js'
import { configForRun } from '../../helpers.js'

const TEST_ID = 'ABCD1234EFGH5678'
const shop = { identifier: 'shop', rootPageId: 7, base: 'https://shop-testing.test/', origin: 'https://shop-testing.test' }

let root: string
let config: ToolkitConfig
let calls: Array<{ url: string; headers: Record<string, string> }>

function recording(): APIRequestContext {
    const record = (url: string, options?: { headers?: Record<string, string> }): Promise<string> => {
        calls.push({ url, headers: options?.headers ?? {} })

        return Promise.resolve('answered')
    }

    return { get: record } as unknown as APIRequestContext
}

beforeEach(() => {
    root = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-request-sites-'))
    config = configForRun(root, 'aaaaaaaaaaaaaaaa')
    ensureRunNamespace(config)
    calls = []
})

afterEach(() => {
    fs.rmSync(root, { recursive: true, force: true })
})

describe('the request client on a run with several sites', () => {
    it('gives an absolute URL on a site of this run the test ID', async () => {
        writeSitesFile(config, { sites: [{ ...shop, available: true }] })

        await toolkitRequest(recording(), config, TEST_ID).get('https://shop-testing.test/cart')

        expect(calls[0].headers[TEST_ID_HEADER]).toBe(TEST_ID)
    })
})
