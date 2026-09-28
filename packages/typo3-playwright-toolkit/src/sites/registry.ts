import * as fs from 'fs'
import * as path from 'path'
import { getToolkitConfig, type ToolkitConfig } from '../config.js'
import { runPaths } from '../state/run-namespace.js'

export interface DiscoveredSite {
    identifier: string
    rootPageId: number
    base: string
}

export type SiteEntry = DiscoveredSite & ({ available: true } | { available: false; reason: string })

export type SitesFile = { sites: SiteEntry[] } | { skipped: string }

export interface ScenarioSite {
    identifier: string
    rootPageId: number
    base: string
    origin: string
}

const SITES_FILE = 'sites.json'
const FALLBACK_ROOT_PAGE_ID = 1

export function parseDiscoveredSites(value: unknown, testingURL: string): DiscoveredSite[] {
    if (!Array.isArray(value)) {
        return []
    }

    return value.flatMap((entry: unknown) => {
        const { identifier, rootPageId, base } = (entry ?? {}) as Record<string, unknown>
        if ('string' !== typeof identifier || 'number' !== typeof rootPageId || 'string' !== typeof base) {
            return []
        }

        return [{ identifier, rootPageId, base: new URL(base, `${testingURL}/`).toString() }]
    })
}

export function writeSitesFile(config: ToolkitConfig, file: SitesFile): void {
    fs.writeFileSync(sitesFile(config), JSON.stringify(file, null, 2))
}

export function readSitesFile(config: ToolkitConfig): SitesFile | undefined {
    try {
        return JSON.parse(fs.readFileSync(sitesFile(config), 'utf8')) as SitesFile
    } catch {
        return undefined
    }
}

function sitesFile(config: ToolkitConfig): string {
    return path.join(runPaths(config).runDir, SITES_FILE)
}

export function resolveScenarioSite(config: ToolkitConfig, file: SitesFile | undefined, requested?: string): ScenarioSite {
    if (undefined === file || 'skipped' in file) {
        if (undefined === requested) {
            return fallbackSite(config)
        }

        const why = undefined === file
            ? 'this run has no site list, so its global setup did not run'
            : `site discovery was skipped (${file.skipped})`

        throw new Error(`[typo3-playwright-toolkit] This scenario names the site "${requested}", but ${why}.`)
    }

    if (undefined === requested && 0 === file.sites.length) {
        return fallbackSite(config)
    }

    const site = undefined === requested
        ? defaultSite(config, file.sites)
        : file.sites.find((entry) => entry.identifier === requested)
    if (undefined === site) {
        throw new Error(
            `[typo3-playwright-toolkit] This installation has no site "${requested}". Its sites: ${names(file.sites)}.`,
        )
    }
    if (!site.available) {
        throw new Error(
            `[typo3-playwright-toolkit] The site "${site.identifier}" (${site.base}) is unavailable: ${site.reason}.`,
        )
    }

    return { identifier: site.identifier, rootPageId: site.rootPageId, base: site.base, origin: new URL(site.base).origin }
}

function fallbackSite(config: ToolkitConfig): ScenarioSite {
    return {
        identifier: '',
        rootPageId: FALLBACK_ROOT_PAGE_ID,
        base: `${config.testingURL}/`,
        origin: new URL(config.testingURL).origin,
    }
}

function defaultSite(config: ToolkitConfig, sites: SiteEntry[]): SiteEntry {
    if (1 === sites.length) {
        return sites[0]
    }

    const host = new URL(config.testingURL).host
    const matching = sites.filter((entry) => new URL(entry.base).host === host)
    if (1 !== matching.length) {
        throw new Error(
            `[typo3-playwright-toolkit] This installation has several sites. Name one with \`site\`: ${names(sites)}.`,
        )
    }

    return matching[0]
}

function names(sites: SiteEntry[]): string {
    return sites.map((site) => site.identifier).join(', ')
}

export function testingOrigins(config: ToolkitConfig, file: SitesFile | undefined = readSitesFile(config)): Set<string> {
    const origins = new Set([new URL(config.testingURL).origin])
    if (undefined !== file && 'sites' in file) {
        for (const site of file.sites) {
            if (site.available) {
                origins.add(new URL(site.base).origin)
            }
        }
    }

    return origins
}

export function siteURL(identifier: string, target = ''): string {
    const config = getToolkitConfig()

    return new URL(target.replace(/^\/+/, ''), resolveScenarioSite(config, readSitesFile(config), identifier).base).toString()
}

export function siteCacheKey(scenarioKey: string, site: ScenarioSite): string {
    return '' === site.identifier ? scenarioKey : `${scenarioKey}@${site.base}#${site.rootPageId}`
}
