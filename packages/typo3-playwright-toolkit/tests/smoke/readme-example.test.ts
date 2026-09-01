import { describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as path from 'path'
import * as rootEntry from '#src/index.js'
import * as playwrightEntry from '#src/playwright/index.js'
import { defineBasePlaywrightConfig, defineToolkitConfig } from '#src/playwright/index.js'
import { GenericTextContent } from '../fixtures/generic-content.js'

const README = fs.readFileSync(path.join(import.meta.dirname, '../../README.md'), 'utf-8')

/**
 * The README's configuration example is the first thing a consumer copies, and it
 * shipped naming an export the `/playwright` entry did not have. Compiling the
 * same call here — and checking the entry against what the README claims to
 * import from it — is what makes the example answerable to CI.
 */
describe('the documented configuration example', () => {
    it('compiles with nothing but the three values the README opens with', () => {
        const toolkitConfig = defineToolkitConfig({
            testingURL: 'https://example-testing.test',
            paths: { consumerRoot: '/srv/project' },
        })

        const config = defineBasePlaywrightConfig(toolkitConfig, { testDir: './tests' })

        expect(config.use?.baseURL).toBe('https://example-testing.test')
        expect(toolkitConfig.paths.stateDir).toBe('/srv/project/.test-state')
    })

    it('compiles with the toolkit config passed straight to the base config', () => {
        const toolkitConfig = defineToolkitConfig({
            testingURL: 'https://example-testing.test',
            contentTypes: { generic_text: GenericTextContent },
            paths: {
                consumerRoot: '/srv/project',
                stateDir: '/srv/project/.test-state',
                sessionDir: '/srv/project/var/session',
            },
            screenshot: { threshold: 0.2, maxDiffPixelRatio: 0.01 },
            hideBeforeScreenshot: ['.cookie-banner'],
        })

        const config = defineBasePlaywrightConfig(toolkitConfig, { testDir: './tests' })

        expect(config.use?.baseURL).toBe('https://example-testing.test')
        expect(config.testDir).toBe('./tests')
    })

    it('keeps what a project that already runs Playwright had in its own config', () => {
        const toolkitConfig = defineToolkitConfig({
            testingURL: 'https://example-testing.test',
            paths: { consumerRoot: '/srv/project' },
        })

        const config = defineBasePlaywrightConfig(toolkitConfig, {
            testDir: './tests',
            fullyParallel: true,
            reporter: [['list'], ['junit', { outputFile: 'junit.xml' }]],
            expect: { toHaveScreenshot: { maxDiffPixelRatio: 0.01 } },
            use: { ignoreHTTPSErrors: true, locale: 'de-DE' },
            projects: [
                { name: 'Desktop Chrome', use: { viewport: { width: 1280, height: 720 } } },
                { name: 'Phone', use: { viewport: { width: 390, height: 844 } } },
            ],
        })

        expect(config.projects?.map((project) => project.name)).toEqual(['Desktop Chrome', 'Phone'])
        expect(config.reporter).toEqual([['list'], ['junit', { outputFile: 'junit.xml' }]])
        expect(config.expect?.toHaveScreenshot?.maxDiffPixelRatio).toBe(0.01)
        expect(config.fullyParallel).toBe(true)
        expect(config.use?.ignoreHTTPSErrors).toBe(true)
        expect(config.use?.locale).toBe('de-DE')
        expect(config.use?.baseURL).toBe('https://example-testing.test')
    })

    it('imports only names the package entry actually exports', () => {
        const pattern = /import \{([^}]+)\} from '@plan2net\/typo3-playwright-toolkit'/g
        const matches = [...README.matchAll(pattern)]

        expect(matches.length).toBeGreaterThan(0)

        for (const match of matches) {
            for (const name of match[1].split(',').map((entry) => entry.trim()).filter(Boolean)) {
                // A type is gone at runtime; tsc is what checks those.
                if (name.startsWith('type ')) {
                    continue
                }

                expect(Object.keys(rootEntry)).toContain(name)
            }
        }
    })

    it('imports only names the /playwright entry actually exports', () => {
        const pattern = /import \{([^}]+)\} from '@plan2net\/typo3-playwright-toolkit\/playwright'/g
        const matches = [...README.matchAll(pattern)]

        expect(matches.length).toBeGreaterThan(0)

        for (const match of matches) {
            for (const name of match[1].split(',').map((entry) => entry.trim()).filter(Boolean)) {
                expect(Object.keys(playwrightEntry)).toContain(name)
            }
        }
    })
})
