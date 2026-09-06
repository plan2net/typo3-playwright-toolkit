// #ddev-generated
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { constants } from 'node:os';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

const args = process.argv.slice(2);

function optionValues(name) {
    const values = [];
    for (let i = 0; i < args.length; i++) {
        if (args[i].startsWith(`${name}=`)) values.push(args[i].slice(name.length + 1));
        else if (args[i] !== name) continue;
        while (i + 1 < args.length && !args[i + 1].startsWith('-')) values.push(args[++i]);
    }
    return values;
}

if (args.includes('--last-failed')) {
    if (process.env.PLAYWRIGHT_LAST_RUN_OUTPUT_FILE && optionValues('--last-failed-file').length === 0) {
        args.push(`--last-failed-file=${process.env.PLAYWRIGHT_LAST_RUN_OUTPUT_FILE}`);
    }
    const directory = mkdtempSync(join(tmpdir(), 'playwright-approve-'));
    try {
        const reportFile = join(directory, 'report.json');
        const discovery = spawnSync('npx', ['playwright', ...args, '--list', '--reporter=json'], {
            encoding: 'utf8',
            env: { ...process.env, PLAYWRIGHT_JSON_OUTPUT_FILE: reportFile },
        });
        if (discovery.status !== 0) {
            throw new Error(discovery.stderr || discovery.stdout || 'Could not load the Playwright configuration.');
        }
        const report = JSON.parse(readFileSync(reportFile, 'utf8'));
        const patterns = optionValues('--project').map(name =>
            new RegExp(`^${name.split('*').map(part => part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('.*')}$`, 'i'));
        const project = report.config.projects.find(project =>
            patterns.length === 0 || patterns.some(pattern => pattern.test(project.name)));
        const override = optionValues('--last-failed-file').at(-1);
        const lastRunFile = override ? resolve(override) : join(project.outputDir, '.last-run.json');
        const lastRun = JSON.parse(readFileSync(lastRunFile, 'utf8'));
        if (!Array.isArray(lastRun.failedTests) || lastRun.failedTests.length === 0 ||
            lastRun.failedTests.some(id => typeof id !== 'string' || id.length === 0)) {
            throw new Error('No failed tests were recorded.');
        }
    } catch (error) {
        console.error(`[playwright] Cannot approve the previous failures: ${error.message}`);
        console.error('[playwright] Run the tests first, select a test filter, or use --all.');
        process.exitCode = 1;
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
    if (process.exitCode) process.exit(process.exitCode);
}

const result = spawnSync('npx', ['playwright', ...args], { stdio: 'inherit' });

if (result.error) console.error(`[playwright] ${result.error.message}`);
process.exit(result.status ?? (result.signal ? 128 + constants.signals[result.signal] : 1));
