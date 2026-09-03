import { getToolkitConfig, type ToolkitConfig } from './config.js'
import { runBuild } from './build.js'
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

    const text = await response.text()
    try {
        return { status: response.status, ok: response.ok, body: JSON.parse(text) as HealthResponse['body'] }
    } catch {
        throw new Error(
            [
                `Preflight failed: ${healthUrl} returned non-JSON (status ${response.status}).`,
                'Either the Playwright test API extension is not loaded in the Testing context,',
                `or the site failed before it answered:`,
                excerpt(text),
                '',
                ...(404 === response.status ? notInTestingContext(healthUrl) : []),
            ].join('\n'),
        )
    }
}

function notInTestingContext(healthUrl: string): string[] {
    return [
        `The endpoint only exists in the Testing context. Check that ${new URL(healthUrl).origin}`,
        'is the hostname your web server maps to TYPO3_CONTEXT=Testing, and that testingURL',
        'in playwright.config.ts names that same hostname.',
    ]
}

function excerpt(body: string): string {
    const collapsed = body.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()

    return collapsed.length > 500 ? `${collapsed.slice(0, 500)}…` : collapsed || '(empty body)'
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

    const { status, body } = await readHealth(
        healthUrl,
        { [SECRET_HEADER]: resolveApiSecret(config) },
        options.fetchImpl ?? fetch,
        (reason) => `Preflight failed: could not read the API version from ${healthUrl} (${reason}).`,
    )

    // Before the version check: a 401 body parses and carries no version, which the
    // check below would report as an extension too old.
    if (401 === status) {
        throw new Error(
            [
                `Preflight failed: ${healthUrl} refused the request.`,
                'The toolkit secret does not match the one the extension expects.',
                '',
                'Under DDEV both sides read var/playwright/api-secret, which',
                '`ddev playwright-prepare` writes. Run it, or set PLAYWRIGHT_TOOLKIT_SECRET',
                'to the same value on both sides when PHP and Node run in separate containers.',
            ].join('\n'),
        )
    }

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

/** Cannot fail while the endpoint itself is gated: here so a moved gate stops the run. */
function assertTestingContext(healthUrl: string, reported: string | undefined): void {
    if (undefined === reported || /^Testing(\/|$)/.test(reported)) {
        return
    }

    throw new Error(
        [
            `Preflight failed: ${healthUrl} answered in the ${reported} context, not Testing.`,
            '',
            ...notInTestingContext(healthUrl),
        ].join('\n'),
    )
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
        assertTestingContext(healthUrl, body.checks?.context?.detail)

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

    // First, so a failed build costs no state and no test database.
    runBuild(config)

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
