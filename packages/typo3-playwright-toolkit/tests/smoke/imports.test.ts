import { describe, expect, it } from 'vitest'
import {
    PageBuilder,
    ContentBuilder,
    takeScreenshot,
    defineToolkitConfig,
    TEST_ID_HEADER,
    TEST_ID_PATTERN,
    type ToolkitConfig,
    type ContentBuilderInterface,
    definePair,
    expect as playwrightExpect,
} from '#src/index.js'
import { expect as playwrightsOwnExpect } from '@playwright/test'
import { createContent } from '#src/builders/content-factory.js'
import { GenericTextContent } from '../fixtures/generic-content.js'

function fixtureConfig(): ToolkitConfig {
    return {
        testingURL: 'https://example-testing.test',
        contentTypes: { generic_text: GenericTextContent },
        paths: {
            consumerRoot: '/srv/project',
            stateDir: '/srv/project/.test-state',
            sessionDir: '/srv/project/var/session',
        },
    }
}

describe('public API smoke', () => {
    it('imports every public symbol', () => {
        expect(PageBuilder).toBeTypeOf('function')
        expect(ContentBuilder).toBeTypeOf('function')
        expect(createContent).toBeTypeOf('function')
        expect(takeScreenshot).toBeTypeOf('function')
        expect(definePair).toBeTypeOf('function')
        expect(defineToolkitConfig).toBeTypeOf('function')
        expect(TEST_ID_HEADER).toBe('X-Playwright-Test-Id')
        expect(TEST_ID_PATTERN).toBeInstanceOf(RegExp)
    })

    it('re-exports expect, so a test file needs one import', () => {
        expect(playwrightExpect).toBeTypeOf('function')
        expect(playwrightExpect).toBe(playwrightsOwnExpect)
    })

    it('builds a trivial generic content type through the registry', () => {
        defineToolkitConfig(fixtureConfig())

        const builder = createContent('generic_text') as ContentBuilderInterface & {
            withHeader(t: string): unknown
        }
        builder.withHeader('Hello')

        expect(builder.type).toBe('generic_text')
        expect(builder.getFields().CType).toBe('generic_text')
        expect(builder.getFields().header).toBe('Hello')
    })
})
