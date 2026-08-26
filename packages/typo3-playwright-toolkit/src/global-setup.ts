import { getToolkitConfig, type ToolkitConfig } from './config.js'
import { prepareRun } from './state/run-namespace.js'
import { REPLAY_TEST_ID, TEST_ID_HEADER, generateTestId } from './contract.js'
import { SECRET_HEADER, resolveApiSecret } from './http/api-secret.js'
import { registerSetupAttempt } from './state/attempt-registry.js'

export function preflightTestId(config: ToolkitConfig): string {
    const testId = generateTestId()
    registerSetupAttempt(config, 'preflight', testId)

    return testId
}

/** The oldest extension this toolkit can talk to. */
export const MINIMUM_API_VERSION = 1

interface HealthResponse {
    status: number
    ok: boolean
    body: { ok?: boolean; api?: unknown; checks?: Record<string, { ok: boolean; detail: string }> }
}

/**
 * The body is read whatever the status: an unhealthy site still reports its
 * version, and "too old" is a different problem from "unhealthy".
 */
async function readHealth(
    healthUrl: string,
    headers: Record<string, string>,
    doFetch: typeof fetch,
    unreachable: (reason: string) => string,
): Promise<HealthResponse> {
    let response: Response
    try {
        response = await doFetch(healthUrl, {
            method: 'GET',
            signal: AbortSignal.timeout(Number(process.env.PW_HEALTH_TIMEOUT_MS) || 5000),
            headers,
        })
    } catch (error) {
        throw new Error(unreachable(error instanceof Error ? error.message : String(error)))
    }

    try {
        return { status: response.status, ok: response.ok, body: (await response.json()) as HealthResponse['body'] }
    } catch {
        throw new Error(
            `Preflight failed: ${healthUrl} returned non-JSON (status ${response.status}). ` +
                'Check that the Playwright test API extension is loaded in the Testing context.',
        )
    }
}

/**
 * Sends no test ID, and runs before any test ID exists. A request with one makes
 * the extension create a database, and an extension this old has no endpoint to
 * delete it again.
 */
export async function verifyApiVersion(
    config: ToolkitConfig,
    options: { fetchImpl?: typeof fetch } = {},
): Promise<number> {
    const healthUrl = `${config.testingURL}/typo3/test-api/health`

    const { body } = await readHealth(
        healthUrl,
        { [SECRET_HEADER]: resolveApiSecret(config) },
        options.fetchImpl ?? fetch,
        (reason) => `Preflight failed: could not read the API version from ${healthUrl} (${reason}).`,
    )
    const reported = body.api

    if (typeof reported !== 'number' || reported < MINIMUM_API_VERSION) {
        throw new Error(
            [
                `Preflight failed: the TYPO3 extension is too old for this toolkit.`,
                `  needs api ${MINIMUM_API_VERSION} or newer, got ${JSON.stringify(reported)}`,
                '',
                'Upgrade it with:  composer update plan2net/playwright-toolkit',
                '',
                'Continuing would create test databases the extension has no endpoint',
                'to clean up again.',
            ].join('\n'),
        )
    }

    return reported
}

export async function runHealthCheck(
    testingURL: string,
    options: { testId?: string; secret?: string; fetchImpl?: typeof fetch } = {},
): Promise<void> {
    const healthUrl = `${testingURL}/typo3/test-api/health`
    const headers: Record<string, string> = {}
    if (options.testId) {
        headers[TEST_ID_HEADER] = options.testId
    }
    if (options.secret) {
        headers[SECRET_HEADER] = options.secret
    }

    const { status, ok, body } = await readHealth(healthUrl, headers, options.fetchImpl ?? fetch, (reason) =>
        [
            `Preflight failed: ${healthUrl} unreachable (${reason}).`,
            'Likely causes:',
            '  - The test environment is not running',
            '  - The testing site configuration is missing',
        ].join('\n'),
    )

    if (ok && body.ok) {
        return
    }

    const failed = Object.entries(body.checks ?? {})
        .filter(([, check]) => !check.ok)
        .map(([name, check]) => `  ✗ ${name}: ${check.detail}`)
        .join('\n')

    throw new Error(
        [
            `Preflight failed (HTTP ${status}):`,
            failed || '  (no per-check detail returned — investigate the endpoint response)',
            '',
            'Fix the underlying issue then re-run. To bypass for endpoint debugging, set PW_SKIP_HEALTH=1.',
        ].join('\n'),
    )
}

async function globalSetup(): Promise<void> {
    const config = getToolkitConfig()
    prepareRun(config)

    if (process.env.PW_SKIP_HEALTH === '1') {
        return
    }

    // Order matters: verify the engine before minting an ID, or a mismatch leaves
    // behind a database teardown can no longer reach.
    await verifyApiVersion(config)

    // Replay checks its own database; a throwaway one its teardown never drops.
    await runHealthCheck(config.testingURL, {
        testId: config.replay ? REPLAY_TEST_ID : preflightTestId(config),
        secret: resolveApiSecret(config),
    })
}

export default globalSetup
