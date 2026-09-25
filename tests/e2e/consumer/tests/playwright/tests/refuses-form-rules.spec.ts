import { defineScenario, expect } from '@plan2net/typo3-playwright-toolkit'

export const test = defineScenario(async ({ builders }) => {
    const refusal = await builders
        .page()
        .withSlug('/untitled')
        .atParentId(1)
        .create()
        .then(
            () => '',
            (error: Error) => error.message,
        )

    const optedOut = await builders.page().withSlug('/untitled-on-purpose').atParentId(1).withoutFormRules().create()

    return { refusal, optedOut: optedOut.id }
})

test('refuses a page the backend form would not save', ({ state }) => {
    expect(state.refusal).toContain('"title" is required.')
})

test('saves the same page when the test opts out', ({ state }) => {
    expect(Number(state.optedOut)).toBeGreaterThan(0)
})
