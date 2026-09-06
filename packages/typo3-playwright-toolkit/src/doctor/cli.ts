#!/usr/bin/env node
import { mkdtempSync, rmSync } from 'node:fs'
import { createRequire } from 'node:module'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { parseArgs } from 'node:util'

try {
    const { values } = parseArgs({ options: {
        help: { type: 'boolean', short: 'h' },
        config: { type: 'string', short: 'c' },
        project: { type: 'string', multiple: true },
    } })
    if (values.help) {
        console.log('Usage: typo3-playwright-doctor [--project name] [--config file]')
        console.log('Checks browsers and TYPO3 without running tests, builds, or repairs.')
    } else {
        const require = createRequire(import.meta.url)

        // Playwright 1.44 writes .last-run.json even in list mode, into an output
        // directory it creates first, so the project's own test-results survives
        // only if that directory is a throwaway.
        const outputDir = mkdtempSync(join(tmpdir(), 'typo3-playwright-doctor-'))
        process.on('exit', () => rmSync(outputDir, { recursive: true, force: true }))

        const args = ['test', '--list', '--pass-with-no-tests', '--output', outputDir,
            '--reporter', fileURLToPath(new URL('./reporter.js', import.meta.url))]
        if (values.config) args.push('--config', values.config)
        for (const project of values.project ?? []) args.push('--project', project)
        const playwrightCli = require.resolve('@playwright/test/cli')
        process.env.PW_DOCTOR_PROJECTS = JSON.stringify(values.project ?? [])
        process.argv = [process.execPath, playwrightCli, ...args]
        await import(pathToFileURL(playwrightCli).href)
    }
} catch (error) {
    console.error(`✗ ${(error as Error).message}`)
    process.exitCode = 1
}
