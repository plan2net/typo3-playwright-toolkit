import { definePair, expect } from '@plan2net/typo3-playwright-toolkit'

const HEADER = 'Hello from the toolkit'

export const test = definePair(async ({ builders }) => {
    const page = await builders.page().withTitle('E2E').withSlug('/e2e').atParentId(1).create()

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
