import { describe, expect, it } from 'vitest'
import { claimScenarioFile } from '#src/scenario.js'

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
