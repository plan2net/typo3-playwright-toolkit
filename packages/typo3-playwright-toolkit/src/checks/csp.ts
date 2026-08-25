import { expect, type BrowserContext, type TestInfo } from '@playwright/test'
import { getToolkitConfig } from '../config.js'

export type CspMode = 'any' | 'report-only' | 'enforced'

export interface CspViolation {
    blockedUri: string
    columnNumber: number
    disposition: SecurityPolicyViolationEventDisposition
    documentUri: string
    effectiveDirective: string
    lineNumber: number
    originalPolicy: string
    sample: string
    sourceFile: string
    violatedDirective: string
}

export interface DocumentPolicy {
    enforced: string | null
    reportOnly: string | null
    url: string
}

const BINDING_NAME = '__playwrightReportCspViolation'

function isPresent(header: string | null): boolean {
    return !!header?.trim()
}

export function isExpectedDocumentOrigin(documentUri: string, expectedOrigin: string): boolean {
    if (!documentUri.trim()) {
        return false
    }

    try {
        const documentOrigin = new URL(documentUri).origin

        return 'null' !== documentOrigin && documentOrigin === expectedOrigin
    } catch {
        return false
    }
}

export function policyIssues(
    policies: DocumentPolicy[],
    requestedDocuments: string[],
    mode: CspMode,
    expectedOrigin: string,
): string[] {
    if (0 === policies.length) {
        if (0 === requestedDocuments.length) {
            return []
        }

        // Documents were asked for but none answered, so nothing was checked.
        return [
            `No successful same-origin HTML document response was observed for ${expectedOrigin}.\n` +
                `Requested documents:\n${requestedDocuments.map((url) => `  - ${url}`).join('\n')}`,
        ]
    }

    return policies.flatMap((policy) => {
        const issues: string[] = []

        if ('report-only' === mode) {
            if (!isPresent(policy.reportOnly)) {
                issues.push(`Missing Content-Security-Policy-Report-Only header: ${policy.url}`)
            }
            if (isPresent(policy.enforced)) {
                issues.push(`Unexpected enforcing Content-Security-Policy header: ${policy.url}`)
            }
        } else if ('enforced' === mode) {
            // A report-only header next to it is normal: sites enforce one policy
            // while testing a stricter one.
            if (!isPresent(policy.enforced)) {
                issues.push(`Missing enforcing Content-Security-Policy header: ${policy.url}`)
            }
        } else if (!isPresent(policy.enforced) && !isPresent(policy.reportOnly)) {
            issues.push(`Document served with no Content-Security-Policy header: ${policy.url}`)
        }

        return issues
    })
}

export function describeViolations(violations: CspViolation[]): string[] {
    const grouped = new Map<string, CspViolation[]>()
    for (const violation of violations) {
        grouped.set(violation.effectiveDirective, [
            ...(grouped.get(violation.effectiveDirective) ?? []),
            violation,
        ])
    }

    return [...grouped.entries()].map(([directive, group]) => {
        const details = group.map((violation) => {
            const source = violation.sourceFile
                ? `${violation.sourceFile}:${violation.lineNumber}:${violation.columnNumber}`
                : violation.documentUri
            const sample = violation.sample ? `\n    Sample: ${violation.sample}` : ''

            return (
                `  - Blocked: ${violation.blockedUri || 'unknown'}\n` +
                `    Document: ${violation.documentUri}\n` +
                `    Source: ${source}\n` +
                `    Disposition: ${violation.disposition}${sample}`
            )
        })

        return `${directive} (${group.length})\n${details.join('\n')}`
    })
}

export class CspVerifier {
    private readonly expectedOrigin: string
    private readonly mode: CspMode
    private readonly documentRequests: string[] = []
    private readonly documentPolicies: DocumentPolicy[] = []
    private readonly violations: CspViolation[] = []
    private installed = false

    constructor(
        private readonly context: BrowserContext,
        options: { expectedOrigin?: string; mode?: CspMode } = {},
    ) {
        const config = getToolkitConfig()
        this.expectedOrigin =
            options.expectedOrigin ?? config.csp?.expectedOrigin ?? new URL(config.testingURL).origin
        this.mode = options.mode ?? config.csp?.mode ?? 'any'
    }

    async install(): Promise<void> {
        this.installed = true
        await this.context.exposeBinding(BINDING_NAME, (_source, violation: CspViolation) => {
            if (isExpectedDocumentOrigin(violation.documentUri, this.expectedOrigin)) {
                this.violations.push(violation)
            }
        })

        await this.context.addInitScript((reportBindingName) => {
            document.addEventListener('securitypolicyviolation', (event) => {
                const report = (
                    globalThis as typeof globalThis & {
                        [key: string]: ((violation: CspViolation) => Promise<void>) | undefined
                    }
                )[reportBindingName]

                if (!report) {
                    return
                }

                void report({
                    blockedUri: event.blockedURI,
                    columnNumber: event.columnNumber,
                    disposition: event.disposition,
                    documentUri: event.documentURI,
                    effectiveDirective: event.effectiveDirective,
                    lineNumber: event.lineNumber,
                    originalPolicy: event.originalPolicy,
                    sample: event.sample,
                    sourceFile: event.sourceFile,
                    violatedDirective: event.violatedDirective,
                })
            })
        }, BINDING_NAME)

        this.context.on('request', (request) => {
            if ('document' === request.resourceType() && this.isExpectedOrigin(request.url())) {
                this.documentRequests.push(request.url())
            }
        })

        this.context.on('response', (response) => {
            const headers = response.headers()
            const contentType = headers['content-type'] ?? ''

            if (
                'document' !== response.request().resourceType() ||
                response.status() < 200 ||
                response.status() >= 300 ||
                !contentType.toLowerCase().includes('text/html') ||
                !this.isExpectedOrigin(response.url())
            ) {
                return
            }

            this.documentPolicies.push({
                enforced: headers['content-security-policy'] ?? null,
                reportOnly: headers['content-security-policy-report-only'] ?? null,
                url: response.url(),
            })
        })
    }

    async assertNoViolations(testInfo?: TestInfo, label = 'csp-report'): Promise<void> {
        // The listeners live in install(). Without them nothing was ever collected,
        // and this would pass for a page it never saw.
        if (!this.installed) {
            throw new Error(
                '[typo3-playwright-toolkit] CspVerifier.assertNoViolations() before install(). ' +
                    'Call install() before the page navigates, or it observes nothing.',
            )
        }

        // Messages from the page arrive in order, so asking the page one more
        // question makes sure the earlier violations have already reached us.
        await Promise.all(
            this.context
                .pages()
                .filter((page) => !page.isClosed())
                .map((page) => page.evaluate(() => undefined)),
        )

        const issues = [
            ...policyIssues(this.documentPolicies, this.documentRequests, this.mode, this.expectedOrigin),
            ...describeViolations(this.violations),
        ]

        if (issues.length > 0 && testInfo) {
            await testInfo.attach(`${label}.json`, {
                body: Buffer.from(
                    JSON.stringify(
                        {
                            expectedOrigin: this.expectedOrigin,
                            mode: this.mode,
                            documentRequests: this.documentRequests,
                            documentPolicies: this.documentPolicies,
                            violations: this.violations,
                        },
                        null,
                        2,
                    ),
                ),
                contentType: 'application/json',
            })
        }

        expect(issues, `CSP verification failed:\n\n${issues.join('\n\n')}`).toHaveLength(0)
    }

    private isExpectedOrigin(url: string): boolean {
        try {
            return new URL(url).origin === this.expectedOrigin
        } catch {
            return false
        }
    }
}
