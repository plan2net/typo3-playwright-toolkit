import type { ToolkitConfig } from '../config.js'

export function announceSetupCache(
    config: ToolkitConfig,
    env: NodeJS.ProcessEnv = process.env,
    write: (line: string) => void = console.warn,
): void {
    if ('1' !== env.PW_REUSE_SETUP) {
        return
    }

    if (true === config.replay) {
        throw new Error(
            [
                '[typo3-playwright-toolkit] --reuse-setup cannot be combined with replay mode.',
                'Replay builds every scenario into one shared database, and a restored delta',
                'empties the tables it carries — it would wipe the other scenarios.',
            ].join('\n'),
        )
    }

    write(
        [
            '[playwright] --reuse-setup is on. Scenario setups are restored from cache, not rebuilt.',
            '             Your specs and a rebuilt template invalidate it; changes to PHP,',
            '             TSconfig, TCA defaults or installed extensions do NOT.',
            '             After changing anything the backend writes records with, run once',
            '             without the flag, or: ddev playwright clean --setup-cache',
        ].join('\n'),
    )
}
