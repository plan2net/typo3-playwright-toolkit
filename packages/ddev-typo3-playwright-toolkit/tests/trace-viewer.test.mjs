import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { chmodSync, copyFileSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const addon = resolve(dirname(fileURLToPath(import.meta.url)), '..');

test('trace serves the Playwright viewer and the selected archive over HTTP', { timeout: 15000 }, async t => {
    const directory = mkdtempSync(join(tmpdir(), 'playwright-trace-test-'));
    const testDirectory = join(directory, 'tests');
    const bin = join(directory, 'bin');
    mkdirSync(testDirectory);
    mkdirSync(bin);
    const archive = Buffer.from('504b0506000000000000000000000000000000000000', 'hex');
    writeFileSync(join(testDirectory, 'saved trace.zip'), archive);
    copyFileSync(join(addon, 'tests/fixtures/trace-npx.sh'), join(bin, 'npx'));
    chmodSync(join(bin, 'npx'), 0o755);

    const child = spawn(join(addon, 'commands/web/playwright'), ['trace', 'saved trace.zip'], {
        cwd: directory,
        env: {
            ...process.env,
            PATH: `${bin}:${process.env.PATH}`,
            PW_ADDON_CONFIG_DIR: addon,
            PW_TEST_DIR: testDirectory,
            DDEV_PRIMARY_URL: 'https://example.ddev.site',
            TRACE_CALLS: join(directory, 'calls'),
            TRACE_PLAYWRIGHT_CLI: join(addon, '../typo3-playwright-toolkit/node_modules/playwright/cli.js'),
            PWTEST_UNDER_TEST: '1',
        },
        stdio: ['ignore', 'pipe', 'pipe'],
    });
    const closed = once(child, 'close');
    t.after(async () => {
        child.kill('SIGTERM');
        await closed;
        rmSync(directory, { recursive: true, force: true });
    });
    let output = '';
    let errors = '';
    child.stderr.on('data', data => { errors += data; });
    await new Promise((resolveReady, reject) => {
        child.on('error', reject);
        child.on('exit', code => reject(new Error(`Viewer exited with ${code}: ${errors}\n${output}`)));
        child.stdout.on('data', data => {
            output += data;
            if (output.includes('Listening on')) resolveReady();
        });
    });

    const response = await fetch('http://127.0.0.1:9325/');
    assert.equal(response.status, 200);
    assert.match(await response.text(), /Playwright Trace Viewer/);
    assert.match(output, /https:\/\/example\.ddev\.site:9325/);
    const trace = new URL(response.url).searchParams.get('trace');
    assert.ok(trace);
    const download = await fetch(new URL(trace, response.url));
    assert.equal(download.status, 200);
    assert.deepEqual(Buffer.from(await download.arrayBuffer()), archive);
});
