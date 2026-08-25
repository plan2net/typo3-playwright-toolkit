import { beforeEach, describe, expect, it } from 'vitest'
import { createContent, registerContentTypes } from '#src/builders/content-factory.js'
import type { ContentFields } from '#src/types/content-builder.js'

class AlphaContent {
    readonly type = 'alpha'

    getFields(): ContentFields {
        return { CType: 'alpha' }
    }
}

beforeEach(() => {
    registerContentTypes({})
})

describe('the content type registry', () => {
    it('creates a registered type', () => {
        registerContentTypes({ alpha: AlphaContent })

        expect(createContent('alpha')).toBeInstanceOf(AlphaContent)
    })

    it('replaces what was registered before', () => {
        registerContentTypes({ alpha: AlphaContent })
        registerContentTypes({ beta: AlphaContent })

        expect(() => createContent('alpha')).toThrow(/not registered/)
    })

    it('names the registered types when one is missing', () => {
        registerContentTypes({ alpha: AlphaContent })

        expect(() => createContent('missing')).toThrow(/alpha/)
    })
})
