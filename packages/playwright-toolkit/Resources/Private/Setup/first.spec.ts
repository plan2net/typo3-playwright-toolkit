import { defineScenario, expect } from '@plan2net/typo3-playwright-toolkit'

const HEADER = 'Hello from the toolkit'

const test = defineScenario(async ({ builders }) => {
    const page = await builders.page().withTitle('First').withSlug('/first').atParentId({{ROOT_PAGE_ID}}).create()

    await builders
        .content()
        .onPage(page.id)
        .ofType('header')
        .configure((content) => content.withHeader(HEADER))
        .create()

    return { slug: page.slug }
})

test('renders what the builders wrote', async ({ page, state }) => {
    await page.goto(state.slug)

    await expect(page.getByText(HEADER)).toBeVisible()
})
