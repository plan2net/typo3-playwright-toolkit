import type { ToolkitConfig } from '../config.js'
import type { SetupCacheUse } from '../state/ensure-state.js'
import { httpSetupCache } from './client.js'
import { cacheKey } from './key.js'

export function setupCacheUse(
    config: ToolkitConfig,
    suiteRoot: string,
    scenarioKey: string,
    env: NodeJS.ProcessEnv = process.env,
): SetupCacheUse | undefined {
    if (true === config.replay) {
        return undefined
    }

    return {
        key: cacheKey(suiteRoot, scenarioKey),
        client: httpSetupCache(config),
        refreshOnly: '1' !== env.PW_REUSE_SETUP,
    }
}
