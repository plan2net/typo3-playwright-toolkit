import { defineScenario, editRecord, expect } from '@plan2net/typo3-playwright-toolkit'

export const test = defineScenario(async ({ builders }) => {
    const page = await builders.page().withTitle('Edited in the backend').withSlug('/edited').atParentId(1).create()

    return { id: page.id }
})

test('opens the backend form of a record the scenario made', async ({ backendPage, state }) => {
    const form = await editRecord(backendPage, 'pages', state.id)

    await expect(form.locator(`[data-formengine-input-name="data[pages][${state.id}][title]"]`)).toHaveValue(
        'Edited in the backend',
    )
})
