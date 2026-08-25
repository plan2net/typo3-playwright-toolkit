import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import type { ToolkitConfig } from '#src/config.js'
import { SECRET_ENV, SECRET_HEADER, resolveApiSecret, secretFileFor } from '#src/http/api-secret.js'

let root: string

function configFor(consumerRoot: string): ToolkitConfig {
    return {
        testingURL: 'https://example-testing.test',
        contentTypes: {},
        paths: {
            consumerRoot,
            stateDir: path.join(consumerRoot, '.test-state'),
            sessionDir: path.join(consumerRoot, 'var/session'),
        },
    }
}

beforeEach(() => {
    root = fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(), 'toolkit-secret-')))
    delete process.env[SECRET_ENV]
})

afterEach(() => {
    delete process.env[SECRET_ENV]
    fs.rmSync(root, { recursive: true, force: true })
})

describe('resolveApiSecret', () => {
    it('reads the file the extension writes', () => {
        const file = secretFileFor(configFor(root))
        fs.mkdirSync(path.dirname(file), { recursive: true })
        fs.writeFileSync(file, 'the-secret-from-the-file')

        expect(resolveApiSecret(configFor(root))).toBe('the-secret-from-the-file')
    })

    it('trims trailing whitespace the file may carry', () => {
        const file = secretFileFor(configFor(root))
        fs.mkdirSync(path.dirname(file), { recursive: true })
        fs.writeFileSync(file, 'the-secret\n')

        expect(resolveApiSecret(configFor(root))).toBe('the-secret')
    })

    // Node and PHP may not share a filesystem; the variable is the way in then.
    it('prefers the environment over the file', () => {
        const file = secretFileFor(configFor(root))
        fs.mkdirSync(path.dirname(file), { recursive: true })
        fs.writeFileSync(file, 'from-the-file')
        process.env[SECRET_ENV] = 'from-the-environment'

        expect(resolveApiSecret(configFor(root))).toBe('from-the-environment')
    })

    it('works from the environment alone', () => {
        process.env[SECRET_ENV] = 'only-the-environment'

        expect(resolveApiSecret(configFor(root))).toBe('only-the-environment')
    })

    it('ignores an empty environment value', () => {
        const file = secretFileFor(configFor(root))
        fs.mkdirSync(path.dirname(file), { recursive: true })
        fs.writeFileSync(file, 'from-the-file')
        process.env[SECRET_ENV] = '   '

        expect(resolveApiSecret(configFor(root))).toBe('from-the-file')
    })

    // The message has to name the command that creates it: nothing else in the
    // toolkit can, and every endpoint refuses until it exists.
    it('explains how to create the secret when there is none', () => {
        expect(() => resolveApiSecret(configFor(root))).toThrow(/playwright-prepare/)
    })

    it('names the environment variable as the other way in', () => {
        expect(() => resolveApiSecret(configFor(root))).toThrow(new RegExp(SECRET_ENV))
    })

    it('treats an empty file as no secret at all', () => {
        const file = secretFileFor(configFor(root))
        fs.mkdirSync(path.dirname(file), { recursive: true })
        fs.writeFileSync(file, '\n')

        expect(() => resolveApiSecret(configFor(root))).toThrow(/playwright-prepare/)
    })
})

describe('the secret header', () => {
    it('is the name the extension compares against', () => {
        expect(SECRET_HEADER).toBe('X-Playwright-Toolkit-Secret')
    })
})
