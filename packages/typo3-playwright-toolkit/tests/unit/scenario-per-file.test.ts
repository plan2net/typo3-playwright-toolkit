import { describe, expect, it } from 'vitest'
import { claimScenarioFile, defineScenario } from '#src/scenario.js'
import { setToolkitConfig, type ToolkitConfig } from '#src/config.js'

// Playwright reads a fixture's options when the fixture is defined, so the setup
// timeout means the config has to be set by then.
describe('defineScenario', () => {
    it('says what is missing when the config is not set yet', () => {
        setToolkitConfig(undefined as unknown as ToolkitConfig)

        expect(() => defineScenario(async () => ({}))).toThrow(/No toolkit config set/)
    })
})

describe('one scenario per file', () => {
    it('refuses a second scenario in the same file', () => {
        claimScenarioFile('/tests/two-scenarios.spec.ts', Symbol('first'))

        expect(() =>
            claimScenarioFile('/tests/two-scenarios.spec.ts', Symbol('second')),
        ).toThrow(/defineScenario more than once/)
    })

    // The fixture resolves once per test, so the owner re-claims its file.
    it('lets the same scenario claim its file again', () => {
        const owner = Symbol('the only one')
        claimScenarioFile('/tests/one-scenario.spec.ts', owner)

        expect(() => claimScenarioFile('/tests/one-scenario.spec.ts', owner)).not.toThrow()
    })
})
