import { afterEach, describe, expect, it } from 'vitest'
import { defineToolkitConfig, getToolkitConfig, setToolkitConfig, type ToolkitConfig } from '#src/config.js'
import { createContent, registerContentTypes } from '#src/builders/content-factory.js'
import type { ContentBuilderInterface, ContentFields } from '#src/types/content-builder.js'

class StubContent implements ContentBuilderInterface {
    readonly type = 'stub'
    getFields(): ContentFields {
        return { CType: this.type }
    }
}

function baseConfig(): ToolkitConfig {
    return {
        testingURL: 'https://example-testing.test',
        contentTypes: { stub: StubContent },
        paths: {
            consumerRoot: '/srv/project',
            stateDir: '/srv/project/.test-state',
            sessionDir: '/srv/project/var/session',
        },
    }
}

afterEach(() => {
    // Reset the singleton between tests by overwriting it; getToolkitConfig
    // throwing is exercised in its own test before any set runs.
    setToolkitConfig(baseConfig())
    registerContentTypes({})
})

describe('defineToolkitConfig path validation', () => {
    it('rejects a state directory outside the consumer root', () => {
        const config = baseConfig()
        config.paths.stateDir = '/tmp/somewhere-else'

        expect(() => defineToolkitConfig(config)).toThrow(/stateDir/)
    })

    it('rejects a session directory outside the consumer root', () => {
        const config = baseConfig()
        config.paths.sessionDir = '/var/lib/typo3'

        expect(() => defineToolkitConfig(config)).toThrow(/sessionDir/)
    })

    it('rejects the consumer root as the state directory', () => {
        const config = baseConfig()
        config.paths.stateDir = config.paths.consumerRoot

        expect(() => defineToolkitConfig(config)).toThrow(/stateDir/)
    })

    it('rejects a relative consumer root', () => {
        const config = baseConfig()
        config.paths.consumerRoot = 'project'

        expect(() => defineToolkitConfig(config)).toThrow(/consumerRoot/)
    })
})

describe('defineToolkitConfig testing url', () => {
    // Every request is built by concatenation, so a trailing slash would send
    // POSTs to //typo3/test-api/… which the extension matches nothing against.
    it.each([
        ['a trailing slash', 'https://example-testing.test/'],
        ['several trailing slashes', 'https://example-testing.test///'],
        ['a default port', 'https://example-testing.test:443'],
    ])('normalises %s to the bare origin', (_case, testingURL) => {
        const resolved = defineToolkitConfig({ ...baseConfig(), testingURL })

        expect(resolved.testingURL).toBe('https://example-testing.test')
    })

    it('keeps a port that is not the default one', () => {
        const resolved = defineToolkitConfig({ ...baseConfig(), testingURL: 'https://example-testing.test:8443/' })

        expect(resolved.testingURL).toBe('https://example-testing.test:8443')
    })

    it.each([
        ['no scheme', 'example-testing.test'],
        ['a scheme that carries no requests', 'file:///srv/project'],
        ['embedded credentials', 'https://user:pass@example-testing.test'],
        ['a query string', 'https://example-testing.test/?debug=1'],
        ['a fragment', 'https://example-testing.test/#top'],
        ['nothing at all', ''],
    ])('refuses %s', (_case, testingURL) => {
        expect(() => defineToolkitConfig({ ...baseConfig(), testingURL })).toThrow(/testingURL/)
    })

    // The test API answers on /typo3/test-api/… at the root, so a subdirectory
    // install cannot work and silently dropping the path would hide that.
    it('refuses a path, rather than dropping it', () => {
        expect(() =>
            defineToolkitConfig({ ...baseConfig(), testingURL: 'https://example-testing.test/subdir' }),
        ).toThrow(/testingURL/)
    })
})

describe('defineToolkitConfig', () => {
    it('resolves onto its own object and leaves the caller\'s untouched', () => {
        delete process.env.PW_RUN_ID
        const config = baseConfig()

        const resolved = defineToolkitConfig(config)

        expect(resolved).not.toBe(config)
        expect(config.runId).toBeUndefined()
        expect(resolved.runId).toMatch(/^[0-9a-f]{16}$/)
        expect(getToolkitConfig()).toBe(resolved)
    })

    it('defaults the state and session directories to the consumer root', () => {
        const resolved = defineToolkitConfig({
                testingURL: 'https://example-testing.test',
            paths: { consumerRoot: '/srv/project' },
        })

        expect(resolved.paths.stateDir).toBe('/srv/project/.test-state')
        expect(resolved.paths.sessionDir).toBe('/srv/project/var/session')
    })

    it('keeps directories the consumer named', () => {
        const config = baseConfig()
        config.paths.stateDir = '/srv/project/build/state'

        const resolved = defineToolkitConfig(config)

        expect(resolved.paths.stateDir).toBe('/srv/project/build/state')
    })

    it('defaults hideBeforeScreenshot to an empty array', () => {
        const config = defineToolkitConfig(baseConfig())
        expect(config.hideBeforeScreenshot ?? []).toEqual([])
    })

    it('registers contentTypes so createContent() works immediately', () => {
        defineToolkitConfig(baseConfig())
        const instance = createContent('stub')
        expect(instance).toBeInstanceOf(StubContent)
    })
})

describe('the config singleton', () => {
    it('returns the config that was set', () => {
        const config = baseConfig()
        setToolkitConfig(config)
        expect(getToolkitConfig()).toBe(config)
    })
})

describe('getToolkitConfig before configuration', () => {
    it('throws a clear error when no config has been set', () => {
        // Force the unset state, then assert.
        ;(setToolkitConfig as unknown as (c: ToolkitConfig | undefined) => void)(undefined)
        expect(() => getToolkitConfig()).toThrow(/No toolkit config set/)
    })
})

describe('defineToolkitConfig replay mode', () => {
    it('reads PW_REPLAY=1 into the resolved config', () => {
        process.env.PW_REPLAY = '1'
        try {
            expect(defineToolkitConfig(baseConfig()).replay).toBe(true)
        } finally {
            delete process.env.PW_REPLAY
        }
    })

    it('is off without PW_REPLAY', () => {
        delete process.env.PW_REPLAY

        expect(defineToolkitConfig(baseConfig()).replay).toBe(false)
    })
})

describe('defineToolkitConfig run id', () => {
    it('puts the resolved run id on the config', () => {
        delete process.env.PW_RUN_ID

        const config = defineToolkitConfig(baseConfig())

        expect(config.runId).toMatch(/^[0-9a-f]{16}$/)
        expect(getToolkitConfig().runId).toBe(config.runId)
    })

    it('keeps a pinned run id', () => {
        const config = defineToolkitConfig({ ...baseConfig(), runId: 'pinned-run-id' })

        expect(config.runId).toBe('pinned-run-id')
    })
})
