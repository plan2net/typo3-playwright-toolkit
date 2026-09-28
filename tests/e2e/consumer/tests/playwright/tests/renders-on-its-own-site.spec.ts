import { defineScenario, expect } from '@plan2net/typo3-playwright-toolkit'

const HEADER = 'Hello from the shop'

export const test = defineScenario(
    async ({ builders }) => {
        const page = await builders.page().withTitle('Shop').withSlug('/e2e').create()

        await builders
            .content()
            .onPage(page.id)
            .ofType('header')
            .configure((content) => content.withHeader(HEADER))
            .create()

        return { slug: page.slug }
    },
    { site: 'shop' },
)

test('renders on its own domain, under its own root', async ({ page, state }) => {
    const response = await page.goto(state.slug)

    expect(new URL(page.url()).host).toBe('t3pw-e2e-shop-testing.ddev.site')
    expect(response?.status()).toBe(200)
    await expect(page.getByText(HEADER)).toBeVisible()
})
