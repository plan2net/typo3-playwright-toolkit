import { chromium, firefox, webkit, type Browser, type BrowserContextOptions } from '@playwright/test'
import type { FullConfig, FullProject, FullResult, Reporter, TestError } from '@playwright/test/reporter'
import { getToolkitConfig, type ToolkitConfig } from '../config.js'
import { generateTestId, TEST_ID_HEADER } from '../contract.js'
import { resolveApiSecret, SECRET_HEADER } from '../http/api-secret.js'
import { httpCleanup } from '../http/cleanup-client.js'
import { isTestingContext, MINIMUM_API_VERSION, readHealth } from '../global-setup.js'

const BROWSERS = { chromium, firefox, webkit }

const BROWSER_TIMEOUT_MS = 10000

export default class DoctorReporter implements Reporter {
    private config?: FullConfig
    private failed = 0

    printsToStdio(): boolean {
        return true
    }

    onBegin(config: FullConfig): void {
        this.config = config
    }

    onError(error: TestError): void {
        console.error(`✗ Playwright config: ${error.message ?? error.value}`)
    }

    async onEnd(result: FullResult): Promise<{ status: FullResult['status'] }> {
        if (!this.config || result.status !== 'passed') {
            return { status: 'failed' }
        }
        console.log(`✓ Playwright config loaded: ${this.config.configFile}`)

        let toolkit: ToolkitConfig
        try {
            toolkit = getToolkitConfig()
        } catch (error) {
            this.fail('Toolkit configuration', (error as Error).message)

            return { status: 'failed' }
        }

        for (const project of this.selectedProjects(this.config)) {
            await this.checkBrowser(project, toolkit)
        }
        await this.checkApi(toolkit)

        console.log(this.failed ? `Not ready: ${this.failed} check(s) failed.` : 'Ready to run tests.')

        return { status: this.failed ? 'failed' : 'passed' }
    }

    /**
     * A reporter's config lists every project whatever --project selected, and the
     * run's suites hold only projects that already have a test file. So the
     * selection is applied here, with Playwright's wildcard and case rules.
     */
    private selectedProjects(config: FullConfig): FullProject[] {
        const names = JSON.parse(process.env.PW_DOCTOR_PROJECTS ?? '[]') as string[]
        if (!names.length) {
            return config.projects
        }
        const patterns = names.map((name) => new RegExp(`^${name.split('*')
            .map((part) => part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('.*')}$`, 'i'))

        return config.projects.filter((project) => patterns.some((pattern) => pattern.test(project.name)))
    }

    private async checkBrowser(project: FullProject, toolkit: ToolkitConfig): Promise<void> {
        const browserName = project.use.browserName ?? 'chromium'
        const label = project.name || browserName
        const connect = connectOptions(project)
        let browser: Browser
        try {
            const launch = { ...project.use.launchOptions }
            if (project.use.headless !== undefined) launch.headless = project.use.headless
            if (project.use.channel !== undefined) launch.channel = project.use.channel
            browser = connect
                ? await BROWSERS[browserName].connect(connect.wsEndpoint, { timeout: BROWSER_TIMEOUT_MS, ...connect })
                : await BROWSERS[browserName].launch({ timeout: BROWSER_TIMEOUT_MS, ...launch })
        } catch (error) {
            this.fail(`Browser [${label}]`, (error as Error).message)

            return
        }
        console.log(`✓ Browser [${label}] ${connect ? 'connected' : 'launched'}`)

        try {
            const page = await browser.newPage(pageOptions(project))
            await page.goto(toolkit.testingURL, { timeout: BROWSER_TIMEOUT_MS, waitUntil: 'domcontentloaded' })
            console.log(`✓ Browser access [${label}]: testing URL reachable`)
        } catch (error) {
            this.fail(`Browser access [${label}]`, (error as Error).message)
        } finally {
            await browser.close()
        }
    }

    private async checkApi(config: ToolkitConfig): Promise<void> {
        let secret: string
        try {
            secret = resolveApiSecret(config)
        } catch (error) {
            this.fail('API secret', (error as Error).message)
            this.skip('API authentication', 'API compatibility', 'Database and session checks')

            return
        }
        const url = `${config.testingURL}/typo3/test-api/health`
        const headers = { [SECRET_HEADER]: secret }
        let preflight: Awaited<ReturnType<typeof readHealth>>
        try {
            preflight = await readHealth(url, headers, fetch, (reason) => `Unreachable: ${reason}. Check the testing hostname and web server.`)
        } catch (error) {
            this.fail('Testing URL', (error as Error).message)
            this.skip('API authentication', 'API compatibility', 'Database and session checks')

            return
        }
        console.log(`✓ Testing URL reachable: ${config.testingURL}`)
        if (preflight.status === 401 || preflight.status === 403) {
            this.fail('API secret', 'Request refused. Check PLAYWRIGHT_TOOLKIT_SECRET or run ddev playwright prepare.')
            this.skip('API compatibility', 'Database and session checks')

            return
        }
        if (!answered(preflight.status)) {
            this.fail('Test API', `HTTP ${preflight.status}. Check the Testing site's PHP and web-server logs.`)
            this.skip('API compatibility', 'Database and session checks')

            return
        }
        console.log('✓ API secret accepted')
        if (typeof preflight.body?.api !== 'number' || !Number.isInteger(preflight.body.api) || preflight.body.api < MINIMUM_API_VERSION) {
            this.fail('Toolkit API version', 'Run composer update plan2net/playwright-toolkit.')
            this.skip('Database and session checks')

            return
        }
        console.log('✓ Toolkit API versions compatible')
        if (!confirmsTestingContext(preflight.body.checks?.context)) {
            this.fail('Testing context', 'Point testingURL at the hostname configured with TYPO3_CONTEXT=Testing.')
            this.skip('Database and session checks')

            return
        }

        const testId = generateTestId()
        try {
            const health = await readHealth(url, { ...headers, [TEST_ID_HEADER]: testId }, fetch,
                (reason) => `Diagnostic request failed: ${reason}`)
            if (!answered(health.status)) {
                this.fail('Test API', `HTTP ${health.status}. Check the Testing site's PHP and web-server logs.`)
                this.skip('Database and session checks')

                return
            }
            if (!confirmsTestingContext(health.body?.checks?.context)) {
                this.fail('Testing context', 'The diagnostic response did not confirm a Testing context.')
                this.skip('Database and session checks')

                return
            }
            const failuresBefore = this.failed
            for (const [name, label] of [
                ['database', 'Test database created from template'],
                ['session', 'Backend session usable'],
            ]) {
                const check = health.body.checks?.[name]
                if (check?.ok === true) {
                    console.log(`✓ ${label}`)
                } else {
                    this.fail(label, `${check?.detail ?? 'No check result returned'}. Run ddev playwright prepare --force.`)
                    if (name === 'database') this.skip('Backend session check')
                    break
                }
            }
            if (this.failed === failuresBefore && (!health.ok || health.body.ok !== true)) {
                this.fail('Test API', 'The health response reports failure without a failed check. Check the PHP logs.')
            }
        } catch (error) {
            this.fail('Test database', (error as Error).message)
        } finally {
            const [cleanup] = await httpCleanup(config).drop([testId])
            if (cleanup?.outcome === 'dropped' || cleanup?.outcome === 'absent') {
                console.log('✓ Diagnostic database removed')
            } else {
                this.fail('Diagnostic database cleanup', `db${testId}: ${cleanup?.outcome ?? 'no result'}`)
            }
        }
    }

    private fail(check: string, detail: string): void {
        console.error(`✗ ${check}: ${detail}`)
        this.failed++
    }

    private skip(...checks: string[]): void {
        for (const check of checks) console.log(`– ${check} skipped`)
    }
}

/** Playwright's own environment override wins, the way it does for a test run. */
function connectOptions(project: FullProject): FullProject['use']['connectOptions'] {
    if (!process.env.PW_TEST_CONNECT_WS_ENDPOINT) {
        return project.use.connectOptions
    }

    return {
        wsEndpoint: process.env.PW_TEST_CONNECT_WS_ENDPOINT,
        headers: process.env.PW_TEST_CONNECT_HEADERS
            ? JSON.parse(process.env.PW_TEST_CONNECT_HEADERS) as Record<string, string>
            : undefined,
        exposeNetwork: process.env.PW_TEST_CONNECT_EXPOSE_NETWORK,
    }
}

/** A toolkit header a consumer configured context-wide must not ride along on a browser request. */
function pageOptions(project: FullProject): BrowserContextOptions {
    const options: BrowserContextOptions = { ...project.use.contextOptions }
    options.extraHTTPHeaders = { ...(project.use.extraHTTPHeaders ?? options.extraHTTPHeaders) }
    for (const header of Object.keys(options.extraHTTPHeaders)) {
        if ([SECRET_HEADER.toLowerCase(), TEST_ID_HEADER.toLowerCase()].includes(header.toLowerCase())) {
            delete options.extraHTTPHeaders[header]
        }
    }
    if (project.use.ignoreHTTPSErrors !== undefined) options.ignoreHTTPSErrors = project.use.ignoreHTTPSErrors
    if (project.use.httpCredentials !== undefined) options.httpCredentials = project.use.httpCredentials
    if (project.use.proxy !== undefined) options.proxy = project.use.proxy

    return options
}

/** 200 is healthy and 503 is a reported check failure; anything else is the site breaking. */
function answered(status: number): boolean {
    return status === 200 || status === 503
}

function confirmsTestingContext(check: { ok: boolean; detail: string } | undefined): boolean {
    return check?.ok === true && isTestingContext(check.detail)
}
