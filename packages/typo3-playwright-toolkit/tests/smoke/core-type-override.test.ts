import { describe, expect, it } from 'vitest'
import { CoreContent, coreContentTypes } from '#src/builders/core-content.js'
import { registerContentTypes, createContent } from '#src/builders/content-factory.js'

class ProjectDividerContent extends CoreContent {
    readonly type = 'div'

    inContainer(containerId: number): this {
        return this.withField('tx_container_parent', containerId)
    }
}

declare module '../../src/builders/core-content.js' {
    interface ContentTypeMap {
        div: ProjectDividerContent
    }
}

describe('a consumer builder registered under a core CType key', () => {
    it('replaces the built-in one, as the README promises', () => {
        registerContentTypes({ div: ProjectDividerContent })

        const element = createContent('div')

        expect(element).toBeInstanceOf(ProjectDividerContent)
        registerContentTypes({})
    })

    it('is what .configure() sees, so its own setters typecheck', () => {
        const element = new ProjectDividerContent()

        element.inContainer(42)

        expect(element.getFields()).toMatchObject({ CType: 'div', tx_container_parent: 42 })
    })

    it('leaves the core keys it does not name alone', () => {
        expect(Object.keys(coreContentTypes())).toContain('text')
    })
})
