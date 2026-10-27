import { beforeEach, describe, expect, it } from 'vitest'
import { setToolkitConfig, type ToolkitConfig } from '#src/config.js'
import { editRecord } from '#src/backend.js'

function config(backendPath?: string): ToolkitConfig {
    return {
        testingURL: 'https://site-testing.test',
        backendPath,
        contentTypes: {},
        paths: {
            consumerRoot: '/srv/project',
            stateDir: '/srv/project/.test-state',
        },
    }
}

/** A page whose backend shows the form in its content frame, or, on an older core, on its own. */
function backend(inFrame: boolean) {
    const visited: string[] = []
    const contentFrame = { name: 'content', locator: () => ({ waitFor: async () => undefined }) }
    const mainFrame = { name: 'main' }
    const page = {
        goto: async (url: string) => {
            visited.push(url)
        },
        mainFrame: () => mainFrame,
        locator: () => ({
            first: () => ({
                waitFor: async () => undefined,
                evaluate: async () => (inFrame ? 'IFRAME' : 'FORM'),
                elementHandle: async () => ({ contentFrame: async () => contentFrame }),
            }),
        }),
    }
    const frames = { contentFrame, mainFrame }

    return { page: page as never, visited, frames }
}

beforeEach(() => {
    setToolkitConfig(config())
})

describe('editRecord', () => {
    it('opens the edit form of the record on the testing host', async () => {
        const { page, visited } = backend(true)

        await editRecord(page, 'pages', 12)

        expect(visited).toEqual(['https://site-testing.test/typo3/record/edit?edit%5Bpages%5D%5B12%5D=edit'])
    })

    it('opens it below a backend that was moved to another entry point', async () => {
        setToolkitConfig(config('/admin'))
        const { page, visited } = backend(true)

        await editRecord(page, 'tt_content', '7')

        expect(visited).toEqual(['https://site-testing.test/admin/record/edit?edit%5Btt_content%5D%5B7%5D=edit'])
    })

    it('answers the content frame, where the backend shows the form', async () => {
        const { page, frames } = backend(true)

        expect(await editRecord(page, 'pages', 12)).toBe(frames.contentFrame)
    })

    it('answers the page itself where the backend shows the form without its frame', async () => {
        const { page, frames } = backend(false)

        expect(await editRecord(page, 'pages', 12)).toBe(frames.mainFrame)
    })
})
