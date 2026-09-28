import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import { fileURLToPath } from 'url'
import { setToolkitConfig, type ToolkitConfig } from '#src/config.js'
import { ensureRunNamespace } from '#src/state/run-namespace.js'
import {
    parseDiscoveredSites,
    readSitesFile,
    resolveScenarioSite,
    siteCacheKey,
    siteURL,
    testingOrigins,
    writeSitesFile,
    type SiteEntry,
} from '#src/sites/registry.js'
import { configForRun } from '../../helpers.js'

let tmpRoot: string
let config: ToolkitConfig

beforeEach(() => {
    tmpRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-sites-'))
    config = configForRun(tmpRoot, 'aaaaaaaaaaaaaaaa')
    ensureRunNamespace(config)
    setToolkitConfig(config)
})

afterEach(() => {
    fs.rmSync(tmpRoot, { recursive: true, force: true })
})

describe('parseDiscoveredSites', () => {
    it('reads the list the contract fixture pins', () => {
        const fixture = JSON.parse(
            fs.readFileSync(
                path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../../../contract/health-sites.json'),
                'utf8',
            ),
        ) as { sites: unknown }

        expect(parseDiscoveredSites(fixture.sites, config.testingURL)).toEqual([
            { identifier: 'corporate', rootPageId: 1, base: 'https://corporate-testing.ddev.site/' },
            { identifier: 'shop', rootPageId: 2573, base: 'https://shop-testing.ddev.site/' },
        ])
    })

    it('resolves a relative base against the testing URL', () => {
        expect(parseDiscoveredSites([{ identifier: 'main', rootPageId: 1, base: '/' }], config.testingURL)).toEqual([
            { identifier: 'main', rootPageId: 1, base: 'https://example-testing.test/' },
        ])
    })

    it('answers no sites for a response without a list', () => {
        expect(parseDiscoveredSites(undefined, config.testingURL)).toEqual([])
    })

    it('drops an entry that does not have the contract shape', () => {
        expect(parseDiscoveredSites([{ identifier: 'shop' }, 'nonsense', null], config.testingURL)).toEqual([])
    })
})

describe('resolveScenarioSite', () => {
    it('falls back to the testing URL and page 1 without a site list', () => {
        expect(resolveScenarioSite(config, undefined)).toEqual({
            identifier: '',
            rootPageId: 1,
            base: 'https://example-testing.test/',
            origin: 'https://example-testing.test',
        })
    })

    it('refuses a named site without a site list', () => {
        expect(() => resolveScenarioSite(config, undefined, 'shop')).toThrow(/names the site "shop".*no site list/)
    })

    it('refuses a named site when discovery was skipped, and says why', () => {
        expect(() => resolveScenarioSite(config, { skipped: 'PW_SKIP_HEALTH=1' }, 'shop')).toThrow(
            /site discovery was skipped \(PW_SKIP_HEALTH=1\)/,
        )
    })

    it('takes the only site', () => {
        expect(resolveScenarioSite(config, { sites: [available('shop', 'https://shop-testing.test/', 7)] })).toEqual({
            identifier: 'shop',
            rootPageId: 7,
            base: 'https://shop-testing.test/',
            origin: 'https://shop-testing.test',
        })
    })

    it('takes the named site', () => {
        const sites = [available('main', 'https://example-testing.test/'), available('shop', 'https://shop-testing.test/', 7)]

        expect(resolveScenarioSite(config, { sites }, 'shop').rootPageId).toBe(7)
    })

    it('takes the site on the testing host when there are several', () => {
        const sites = [available('shop', 'https://shop-testing.test/'), available('main', 'https://example-testing.test/')]

        expect(resolveScenarioSite(config, { sites }).identifier).toBe('main')
    })

    it('asks for a site when none is on the testing host', () => {
        const sites = [available('shop', 'https://shop-testing.test/'), available('blog', 'https://blog-testing.test/')]

        expect(() => resolveScenarioSite(config, { sites })).toThrow(
            'This installation has several sites. Name one with `site`: shop, blog.',
        )
    })

    it('asks for a site when two share the testing host', () => {
        const sites = [
            available('main', 'https://example-testing.test/'),
            available('shop', 'https://example-testing.test/shop/'),
        ]

        expect(() => resolveScenarioSite(config, { sites })).toThrow(/several sites/)
    })

    it('falls back when the installation has no site at all', () => {
        expect(resolveScenarioSite(config, { sites: [] }).base).toBe('https://example-testing.test/')
    })

    it('names the sites there are when the named one does not exist', () => {
        const sites = [available('main', 'https://example-testing.test/'), available('blog', 'https://blog-testing.test/')]

        expect(() => resolveScenarioSite(config, { sites }, 'shop')).toThrow(/no site "shop"\. Its sites: main, blog\./)
    })

    it('gives the reason an unavailable site failed its probe', () => {
        const sites: SiteEntry[] = [
            { identifier: 'shop', rootPageId: 7, base: 'https://shop.example/', available: false, reason: 'it redirects to https://login.example/' },
        ]

        expect(() => resolveScenarioSite(config, { sites }, 'shop')).toThrow(
            'The site "shop" (https://shop.example/) is unavailable: it redirects to https://login.example/.',
        )
    })
})

describe('testingOrigins', () => {
    it('holds the testing URL and every available site, not an unavailable one', () => {
        const sites: SiteEntry[] = [
            available('shop', 'https://shop-testing.test/'),
            { identifier: 'blog', rootPageId: 3, base: 'https://blog.example/', available: false, reason: 'x' },
        ]

        expect(testingOrigins(config, { sites })).toEqual(new Set(['https://example-testing.test', 'https://shop-testing.test']))
    })

    it('reads the site list of this run', () => {
        writeSitesFile(config, { sites: [available('shop', 'https://shop-testing.test/')] })

        expect(testingOrigins(config).has('https://shop-testing.test')).toBe(true)
    })
})

describe('siteURL', () => {
    it('keeps the base path for a relative target', () => {
        writeSitesFile(config, { sites: [available('shop', 'https://example-testing.test/shop/')] })

        expect([siteURL('shop', 'cart'), siteURL('shop')]).toEqual([
            'https://example-testing.test/shop/cart',
            'https://example-testing.test/shop/',
        ])
    })

    it('keeps the base path for a slug, which TYPO3 always roots', () => {
        writeSitesFile(config, { sites: [available('shop', 'https://example-testing.test/shop/')] })

        expect(siteURL('shop', '/e2e')).toBe('https://example-testing.test/shop/e2e')
    })
})

describe('siteCacheKey', () => {
    it('changes with the root page of the same site', () => {
        const first = resolveScenarioSite(config, { sites: [available('shop', 'https://shop-testing.test/', 7)] })
        const second = resolveScenarioSite(config, { sites: [available('shop', 'https://shop-testing.test/', 8)] })

        expect(siteCacheKey('scenario', first)).not.toBe(siteCacheKey('scenario', second))
    })

    it('keeps the key of a run without discovered sites', () => {
        expect(siteCacheKey('scenario', resolveScenarioSite(config, undefined))).toBe('scenario')
    })
})

describe('readSitesFile', () => {
    it('reads back what was written', () => {
        writeSitesFile(config, { skipped: 'PW_SKIP_HEALTH=1' })

        expect(readSitesFile(config)).toEqual({ skipped: 'PW_SKIP_HEALTH=1' })
    })

    it('answers undefined when global setup never wrote one', () => {
        expect(readSitesFile(config)).toBeUndefined()
    })
})

function available(identifier: string, base: string, rootPageId = 1): SiteEntry {
    return { identifier, base, rootPageId, available: true }
}
