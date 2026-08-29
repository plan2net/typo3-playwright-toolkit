import { describe, expect, it } from 'vitest'
import {
    PageBuilder,
    ContentBuilder,
    expectScreenshot,
    defineToolkitConfig,
    TEST_ID_HEADER,
    TEST_ID_PATTERN,
    type ToolkitConfig,
    type ContentBuilderInterface,
    CoreContent,
    type RelationOwner,
    type RelationOutput,
    defineScenario,
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
        expect(expectScreenshot).toBeTypeOf('function')
        expect(defineScenario).toBeTypeOf('function')
        expect(defineToolkitConfig).toBeTypeOf('function')
        expect(TEST_ID_HEADER).toBe('X-Playwright-Test-Id')
        expect(TEST_ID_PATTERN).toBeInstanceOf(RegExp)
    })

    it('re-exports expect, so a test file needs one import', () => {
        expect(playwrightExpect).toBeTypeOf('function')
        expect(playwrightExpect).toBe(playwrightsOwnExpect)
    })

    // getRelations is part of the interface, so a builder that defers its relations
    // has to be able to name the types it takes and returns.
    it('lets a content type of its own carry the relation types', () => {
        class Deferred extends CoreContent {
            readonly type = 'deferred'

            override getRelations(owner: RelationOwner): RelationOutput {
                this.withFileReference('assets', 1)

                return super.getRelations(owner)
            }
        }

        const relations = new Deferred().getRelations({ pid: '3', sys_language_uid: 0 })

        expect(relations.columns.assets).toMatch(/^NEW/)
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
