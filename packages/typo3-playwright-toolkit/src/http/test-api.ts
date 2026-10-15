import * as fs from 'fs'

export const DEFAULT_BACKEND_PATH = '/typo3'

export interface TestApiTarget {
    testingURL: string
    backendPath?: string
}

export function normaliseBackendPath(backendPath: string): string {
    if (/[?#:]/.test(backendPath)) {
        throw new Error(
            `[typo3-playwright-toolkit] backendPath must be the path of BE/entryPoint, such as /admin, got "${backendPath}".`,
        )
    }

    const segments = backendPath.split('/').filter((segment) => '' !== segment)

    return 0 === segments.length ? DEFAULT_BACKEND_PATH : `/${segments.join('/')}`
}

export function readTestApiTarget(file: string): TestApiTarget | undefined {
    let recorded: { testingURL?: unknown; backendPath?: unknown } | null
    try {
        recorded = JSON.parse(fs.readFileSync(file, 'utf-8')) as typeof recorded
    } catch {
        return undefined
    }

    if ('string' !== typeof recorded?.testingURL) {
        return undefined
    }

    return {
        testingURL: recorded.testingURL,
        backendPath: 'string' === typeof recorded.backendPath ? recorded.backendPath : undefined,
    }
}

export function testApiUrl(target: TestApiTarget, endpoint: string): string {
    return `${target.testingURL}${target.backendPath ?? DEFAULT_BACKEND_PATH}/test-api/${endpoint}`
}
