import { defineScenario, expect } from '@plan2net/typo3-playwright-toolkit'

const SCOPED_FOLDER = /_processed_[A-Z0-9]{16}\//

export const test = defineScenario(async ({ builders }) => {
    const page = await builders.page().withTitle('E2E images').withSlug('/e2e-images').atParentId(1).create()

    return { slug: page.slug }
})

// The page TypoScript renders both images on every page of every scenario, so this
// runs while the other scenarios convert the same two files.
test('processes images into folders of its own', async ({ page, state }) => {
    await page.goto(state.slug)

    const outsideAnyStorage = page.getByAltText('outside any storage')
    const insideAStorage = page.getByAltText('inside a storage')

    await expect(outsideAnyStorage).toHaveAttribute('src', SCOPED_FOLDER)
    await expect(insideAStorage).toHaveAttribute('src', SCOPED_FOLDER)

    // A collision serves the original or nothing at all, and both still render a tag.
    expect(await outsideAnyStorage.evaluate((image: HTMLImageElement) => image.naturalWidth)).toBe(120)
    expect(await insideAStorage.evaluate((image: HTMLImageElement) => image.naturalWidth)).toBe(100)
})
