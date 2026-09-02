import { defineScenario, expect } from '@plan2net/typo3-playwright-toolkit'

const FIRST = 'Batched first'
const SECOND = 'Batched second'
const THIRD = 'Batched third'

// Only a real TYPO3 answers whether the edit route takes records its URL does not name.
export const test = defineScenario(async ({ builders }) => {
    const page = await builders.page().withTitle('Batch').withSlug('/batch').atParentId(1).create()

    const created = await builders.batch(
        builders
            .content()
            .onPage(page.id)
            .ofType('header')
            .configure((content) => content.withHeader(FIRST)),
        builders
            .content()
            .onPage(page.id)
            .ofType('header')
            .configure((content) => content.withHeader(SECOND)),
        builders
            .content()
            .onPage(page.id)
            .ofType('header')
            .configure((content) => content.withHeader(THIRD)),
    )

    return { slug: page.slug, ids: created.map((element) => element.id) }
})

test('creates every element the one request carried', async ({ page, state }) => {
    expect(new Set(state.ids)).toHaveProperty('size', 3)
    for (const id of state.ids) {
        expect(Number(id)).toBeGreaterThan(0)
    }

    await page.goto(state.slug)

    for (const header of [FIRST, SECOND, THIRD]) {
        await expect(page.getByText(header)).toBeVisible()
    }
})

test('lays them out in the order they were queued', async ({ page, state }) => {
    await page.goto(state.slug)

    const rendered = await page.locator('body').innerText()

    expect(rendered.indexOf(FIRST)).toBeLessThan(rendered.indexOf(SECOND))
    expect(rendered.indexOf(SECOND)).toBeLessThan(rendered.indexOf(THIRD))
})
