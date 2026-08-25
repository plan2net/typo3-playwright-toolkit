import { expect, test, type Page } from '@playwright/test'
import type { AxeResults, ElementContext, RunOptions } from 'axe-core'
import { createRequire } from 'module'
import * as fs from 'fs'
import { getToolkitConfig, type ToolkitAccessibilityConfig } from '../config.js'

// Not AAA: it includes rules most sites intentionally do not meet.
export const DEFAULT_SCAN_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice']

export interface AccessibilityScanOptions {
    disabledRules?: string[]
    include?: string
    exclude?: string
    tags?: string[]
    /** Normally taken from test.info(); injectable so this is testable. */
    projectName?: string
}

export interface AxeRunInput {
    include: string | null
    exclude: string | null
    disabledRules: string[]
    tags: string[]
}

let cachedAxeSource: string | undefined

export function axeSource(): string {
    if (undefined === cachedAxeSource) {
        const require = createRequire(import.meta.url)
        cachedAxeSource = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf-8')
    }

    return cachedAxeSource
}

// No configured list means every project: scanning nowhere would leave the suite
// green while proving nothing.
export function shouldScanAutomatically(
    outcome: { status: string; navigated: boolean },
    config?: { auto?: boolean },
): boolean {
    return true === config?.auto && outcome.navigated && 'failed' !== outcome.status
}

export function shouldScanProject(projectName: string, projects: string[] | undefined): boolean {
    return !projects || projects.length === 0 || projects.includes(projectName)
}

export function buildAxeRunInput(
    options: AccessibilityScanOptions,
    config: ToolkitAccessibilityConfig | undefined,
): AxeRunInput {
    const disabled = new Set([...(config?.disabledRules ?? []), ...(options.disabledRules ?? [])])

    // axe checks the headings of one part against the whole page, so a component
    // that starts at h3 fails because of the page's h1. A scan of the whole page
    // still checks the heading order.
    if (options.include) {
        disabled.add('heading-order')
    }

    return {
        include: options.include ?? null,
        exclude: options.exclude ?? null,
        disabledRules: [...disabled],
        tags: options.tags ?? config?.tags ?? DEFAULT_SCAN_TAGS,
    }
}

function currentProjectName(options: AccessibilityScanOptions): string {
    if (options.projectName) {
        return options.projectName
    }

    try {
        return test.info().project.name
    } catch {
        return ''
    }
}

/**
 * @returns axe's raw results, or null when this project is not scanned
 */
export async function scanAccessibility(
    page: Page,
    options: AccessibilityScanOptions = {},
): Promise<AxeResults | null> {
    const config = getToolkitConfig().accessibility
    const projectName = currentProjectName(options)

    if (!shouldScanProject(projectName, config?.projects)) {
        // Said out loud: a project renamed in playwright.config.ts but not in the
        // toolkit config would otherwise leave every a11y assertion passing on
        // nothing.
        console.warn(
            `[typo3-playwright-toolkit] No accessibility scan for project "${projectName}": ` +
                `accessibility.projects is [${(config?.projects ?? []).join(', ')}].`,
        )

        return null
    }

    const hasAxe = await page.evaluate(() => 'object' === typeof (window as unknown as { axe?: unknown }).axe)
    if (!hasAxe) {
        await page.evaluate(axeSource())
    }

    // window.axe.run() directly rather than @axe-core/playwright's analyze():
    // ~100-300ms against ~1s, which it spends on cross-frame orchestration this
    // needs. The trade-off is no cross-origin iframe traversal.
    return (await page.evaluate(({ include, exclude, disabledRules, tags }) => {
        const axe = (
            window as unknown as {
                axe: { run: (context: ElementContext, options: RunOptions) => Promise<AxeResults> }
            }
        ).axe
        const context = (
            include || exclude ? { ...(include && { include }), ...(exclude && { exclude }) } : document
        ) as ElementContext

        return axe.run(context, {
            runOnly: { type: 'tag', values: tags },
            rules: Object.fromEntries(disabledRules.map((rule) => [rule, { enabled: false }])),
        })
    }, buildAxeRunInput(options, config)))
}

/** Use scanAccessibility instead when you need the results themselves. */
export async function runAccessibilityScan(
    page: Page,
    options: AccessibilityScanOptions = {},
): Promise<void> {
    const results = await scanAccessibility(page, options)
    if (null === results) {
        return
    }

    // An empty or inert region passes vacuously, which is worse than a violation.
    const evaluatedRules =
        results.violations.length + results.passes.length + results.incomplete.length
    expect(
        evaluatedRules,
        'accessibility scan evaluated no rules — the scanned region is empty or inert',
    ).toBeGreaterThan(0)

    expect(results.violations).toEqual([])
}
