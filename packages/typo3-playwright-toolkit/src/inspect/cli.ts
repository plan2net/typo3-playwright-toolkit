#!/usr/bin/env node
import * as fs from 'fs'
import * as path from 'path'
import { findStateDir, inspectLinks } from './links.js'
import { INSPECT_TOKEN_LIFETIME_MS } from './token.js'

function readSecret(stateDir: string): string {
    const fromEnvironment = process.env.PLAYWRIGHT_TOOLKIT_SECRET?.trim()
    if (fromEnvironment) {
        return fromEnvironment
    }

    // The state directory sits in the project root, next to var/.
    const file = path.join(path.dirname(stateDir), 'var/playwright/api-secret')

    return fs.existsSync(file) ? fs.readFileSync(file, 'utf-8').trim() : ''
}

const stateDir = findStateDir(process.cwd())
if (undefined === stateDir) {
    console.error('No .test-state directory found. Run the tests once first.')
    process.exit(1)
}

const secret = readSecret(stateDir)
if ('' === secret) {
    console.error('No API secret found. Run "ddev playwright-prepare" first.')
    process.exit(1)
}

const links = inspectLinks(stateDir, secret)
if (0 === links.length) {
    console.error('No test databases recorded. They are removed when a run passes.')
    process.exit(1)
}

const filter = process.argv[2]
const shown = undefined === filter ? links : links.filter((link) => link.key.includes(filter))

console.log(`Links are valid for ${INSPECT_TOKEN_LIFETIME_MS / 1000} seconds and log you into the backend.\n`)
for (const link of shown) {
    console.log(`${link.key}\n  db${link.testId}\n  ${link.url}\n`)
}
