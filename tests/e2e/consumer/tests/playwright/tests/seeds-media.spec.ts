import { defineScenario, expect } from '@plan2net/typo3-playwright-toolkit'

export const test = defineScenario(async ({ builders }) => {
    const page = await builders.page().withTitle('E2E media').withSlug('/e2e-media').atParentId(1).create()
    await builders
        .content()
        .onPage(page.id)
        .ofType('textmedia')
        .configure((element) => element.withHeader('Seeded media', 'h2').withFile('hero.png'))
        .create()

    return { slug: page.slug }
})

// The only place the whole chain runs: prepare copies the file into a storage and
// indexes it, writes the manifest, the builder turns the name back into a uid, and
// TYPO3 serves the bytes. The alternative text comes from media.json, so a rendered
// alt attribute also proves the metadata reached sys_file_metadata.
test('renders a media fixture referenced by name', async ({ page, state }) => {
    await page.goto(state.slug)

    const image = page.getByAltText('seeded by name')

    await expect(image).toHaveAttribute('src', /playwright-media/)
    expect(await image.evaluate((element: HTMLImageElement) => element.naturalWidth)).toBeGreaterThan(0)
})
