import { describe, expect, it } from 'vitest'
import type { BrowserContext } from '@playwright/test'
import type { ToolkitConfig } from '#src/config.js'
import { preparePairContext } from '#src/http/prepare-context.js'

const TEST_ID = 'ABCD1234EFGH5678'

function config(prepareContext?: ToolkitConfig['prepareContext']): ToolkitConfig {
    return {
        testingURL: 'https://site-testing.test',
        contentTypes: {},
        paths: {
            consumerRoot: '/srv/project',
            stateDir: '/srv/project/.test-state',
            sessionDir: '/srv/project/var/session',
        },
        prepareContext,
    }
}

function fakeContext(routed: string[]): BrowserContext {
    return {
        route: async () => {
            routed.push('toolkit-headers')
        },
    } as unknown as BrowserContext
}

describe('preparing the context every pair test runs in', () => {
    it('routes the toolkit headers even when the consumer set no hook', async () => {
        const routed: string[] = []

        await preparePairContext(fakeContext(routed), config(), TEST_ID)

        expect(routed).toEqual(['toolkit-headers'])
    })

    it('gives the consumer hook the context, so it can stub what a vendor script does', async () => {
        const routed: string[] = []
        const seen: BrowserContext[] = []
        const context = fakeContext(routed)

        await preparePairContext(context, config((given) => void seen.push(given)), TEST_ID)

        expect(seen).toEqual([context])
    })

    it('registers the toolkit route after the hook, so nothing can be added in front of it', async () => {
        const order: string[] = []
        const context = fakeContext(order)

        await preparePairContext(context, config(() => void order.push('consumer-hook')), TEST_ID)

        expect(order).toEqual(['consumer-hook', 'toolkit-headers'])
    })

    it('waits for a hook that returns a promise before registering its own route', async () => {
        const order: string[] = []
        const context = fakeContext(order)

        await preparePairContext(
            context,
            config(async () => {
                await Promise.resolve()
                order.push('consumer-hook')
            }),
            TEST_ID,
        )

        expect(order).toEqual(['consumer-hook', 'toolkit-headers'])
    })
})
