import * as path from 'path'
import type { ToolkitConfig } from '#src/config.js'

export function configForRun(root: string, runId?: string): ToolkitConfig {
    return {
        testingURL: 'https://example-testing.test',
        contentTypes: {},
        paths: {
            consumerRoot: root,
            stateDir: path.join(root, '.test-state'),
            sessionDir: path.join(root, 'var/session'),
        },
        ...(runId === undefined ? {} : { runId }),
    }
}
