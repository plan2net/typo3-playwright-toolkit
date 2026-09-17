#!/usr/bin/env node
import * as path from 'path'
import type { ToolkitConfig } from '../config.js'
import { sweepOrphans } from '../global-teardown.js'
import { httpCleanup } from '../http/cleanup-client.js'
import { findStateDir, recordedTestingUrl } from '../inspect/links.js'
import { OWNER_ACTIVE_MS } from '../state/run-namespace.js'
import { discardSetupCache } from '../setup-cache/discard.js'

if (process.argv.includes('--help') || process.argv.includes('-h')) {
    console.log('Usage: typo3-playwright-clean [--setup-cache]')
    console.log('Drops the test databases and run state a stopped run left behind.')
    console.log('  --setup-cache  also drop every cached scenario delta')
    process.exit(0)
}

const stateDir = findStateDir(process.cwd())
if (undefined === stateDir) {
    console.log('Nothing to clean: no .test-state directory here.')
    process.exit(0)
}

const testingURL = recordedTestingUrl(stateDir)
if (undefined === testingURL) {
    console.log('Nothing to clean: no run recorded a testing URL.')
    process.exit(0)
}

const consumerRoot = path.dirname(stateDir)
const config: ToolkitConfig = {
    testingURL,
    paths: { consumerRoot, stateDir, sessionDir: path.join(consumerRoot, 'var/session') },
    // The window prepareRun uses to decide another process owns a run, so a run
    // younger than it keeps its databases and state.
    cleanup: { orphanAgeMs: OWNER_ACTIVE_MS },
}

const reclaimed = await sweepOrphans(config, httpCleanup(config))

console.log(`Dropped ${reclaimed} test database${1 === reclaimed ? '' : 's'}.`)

if (process.argv.includes('--setup-cache')) {
    const discarded = discardSetupCache(consumerRoot)

    console.log(`Dropped ${discarded} cached scenario setup${1 === discarded ? '' : 's'}.`)
}
