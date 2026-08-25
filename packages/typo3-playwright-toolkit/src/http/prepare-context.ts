import type { BrowserContext } from '@playwright/test'
import type { ToolkitConfig } from '../config.js'
import { applyToolkitHeaders } from './off-site-headers.js'

export async function preparePairContext(
    context: BrowserContext,
    config: ToolkitConfig,
    testId: string,
): Promise<void> {
    await config.prepareContext?.(context)
    await applyToolkitHeaders(context, config, testId)
}
