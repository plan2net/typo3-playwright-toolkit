#!/usr/bin/env node
/**
 * Writes one version into every file that repeats it, so a release cannot ship
 * with two different numbers in it.
 *
 *   node bin/bump-version.mjs 0.2.0
 *
 * Composer and the DDEV add-on take their version from the git tag and declare
 * none. These two do: npm publishes what package.json says, and a classic-mode
 * install reads ext_emconf.php. release.yml checks both against the tag.
 */
import { execFileSync } from 'node:child_process'
import * as fs from 'node:fs'
import * as path from 'node:path'
import { fileURLToPath } from 'node:url'

const repoRoot = path.join(path.dirname(fileURLToPath(import.meta.url)), '..')
const version = process.argv[2]

if (!/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/.test(version ?? '')) {
    console.error('Usage: node bin/bump-version.mjs <x.y.z>')
    process.exit(1)
}

const npmPackage = path.join(repoRoot, 'packages/typo3-playwright-toolkit')
execFileSync('npm', ['version', version, '--no-git-tag-version', '--allow-same-version'], {
    cwd: npmPackage,
    stdio: 'pipe',
})

const emconfPath = path.join(repoRoot, 'packages/playwright-toolkit/ext_emconf.php')
const emconf = fs.readFileSync(emconfPath, 'utf-8')
const versionKey = /('version'\s*=>\s*')[^']*(')/

// Checked before replacing: an unchanged file means the version already matched,
// which is not the same as the key having gone missing.
if (!versionKey.test(emconf)) {
    console.error(`bump-version: no 'version' key found in ${emconfPath}`)
    process.exit(1)
}
fs.writeFileSync(emconfPath, emconf.replace(versionKey, `$1${version}$2`))

console.log(`${version}: package.json, package-lock.json, ext_emconf.php`)
console.log(`Commit, then: git tag v${version} && git push --tags`)
