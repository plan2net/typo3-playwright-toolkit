import type { TestInfo } from '@playwright/test'
import { describe, expect, it } from 'vitest'
import { resolveSetupOutcome } from '#src/scenario.js'
import type { ToolkitConfig } from '#src/config.js'

const config = {
    testingURL: 'https://example-testing.ddev.site',
    paths: { consumerRoot: '/app' },
} as ToolkitConfig

const testInfo = { file: '/app/tests/teaser.spec.ts', attach: async () => undefined } as unknown as TestInfo

describe('resolveSetupOutcome', () => {
    it('reports what TYPO3 recorded when the setup threw', async () => {
        const reported: string[] = []

        const thrown = await resolveSetupOutcome(
            config,
            'teaser',
            testInfo,
            () => Promise.reject(new Error('pages did not save')),
            async (_config, key) => {
                reported.push(key)
                throw new Error('pages did not save\n\nTYPO3 recorded 1 error')
            },
        ).catch((error: Error) => error)

        expect(reported).toEqual(['teaser'])
        expect(thrown.message).toContain('TYPO3 recorded 1 error')
    })
})
