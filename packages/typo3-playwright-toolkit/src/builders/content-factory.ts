import type { ContentBuilderInterface } from '../types/content-builder.js'
import type { ContentTypeConstructor } from '../config.js'
import { coreContentTypes } from './core-content.js'

const registry = new Map<string, ContentTypeConstructor>()

/**
 * Core CTypes are always registered. A consumer key with the same name replaces
 * the shipped builder. Each call replaces the whole set, so calling
 * defineToolkitConfig twice gives the same result as calling it once.
 */
export function registerContentTypes(types: Record<string, ContentTypeConstructor>): void {
    registry.clear()
    for (const [type, constructor] of Object.entries({ ...coreContentTypes(), ...types })) {
        registry.set(type, constructor)
    }
}

export function createContent(type: string): ContentBuilderInterface {
    const Constructor = registry.get(type) ?? coreContentTypes()[type]
    if (!Constructor) {
        throw new Error(
            `[typo3-playwright-toolkit] Content type "${type}" is not registered. ` +
                `Core CTypes ship with the toolkit; add your own to contentTypes in ` +
                `defineToolkitConfig(...). Registered: [${[...registry.keys()].sort().join(', ')}].`,
        )
    }

    return new Constructor()
}
