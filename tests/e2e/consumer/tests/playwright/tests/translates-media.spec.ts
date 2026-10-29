import { defineScenario, expect } from '@plan2net/typo3-playwright-toolkit'

export const test = defineScenario(async ({ builders }) => {
    const page = await builders
        .page()
        .withTitle('E2E products')
        .withSlug('/e2e-products')
        .withFileReference('media', 'hero.png')
        .atParentId(1)
        .create()

    const gallery = await builders
        .content()
        .onPage(page.id)
        .ofType('textmedia')
        .configure((element) => element.withHeader('Two images', 'h2'))
        .withFileReference('assets', 'hero.png', { alternative: 'first image' })
        .withFileReference('assets', 'hero.png', { alternative: 'second image' })
        .create()

    const seeded = await builders
        .content()
        .onPage(page.id)
        .ofType('textmedia')
        .configure((element) => element.withHeader('Seeded metadata', 'h2').withFile('hero.png'))
        .create()

    const badge = await builders
        .content()
        .onPage(page.id)
        .ofType('textmedia')
        .configure((element) => element.withHeader('A badge', 'h2').withFile('badge.svg'))
        .create()

    const free = await builders
        .content()
        .onPage(page.id)
        .ofType('header')
        .configure((element) => element.withHeader('Free copy', 'h2'))
        .create()

    const translatedPage = await builders.page().translate(page.id, 1).withTitle('E2E Produkte').withSlug('/e2e-produkte').create()
    const untouchedPage = await builders.page().translate(
        (await builders.page().withTitle('E2E untouched').withSlug('/e2e-untouched').atParentId(1).create()).id,
        1,
    ).create()

    await builders
        .content()
        .translate(gallery.id, 1, 'textmedia')
        .configure((element) => element.withHeader('Zwei Bilder', 'h2'))
        .withCopiedFileReference('assets', 1, { alternative: 'zweites Bild' })
        .withFileReference('assets', 'hero.png', { alternative: 'drittes Bild' })
        .create()
    await builders.content().translate(seeded.id, 1).withField('header', 'Metadaten').create()
    await builders.content().translate(badge.id, 1).withField('header', 'Ein Abzeichen').create()
    await builders.content().copyTo(free.id, 1).withField('header', 'Frei kopiert').create()
    await builders.fileMetadata('badge.svg').translate(1).withField('alternative', 'ein Abzeichen').create()

    const shared = await builders.page().withTitle('E2E shared layout').withSlug('/e2e-shared').atParentId(1).create()
    const refused = await builders.page().translate(shared.id, 1).withField('layout', 1).refusal()

    return { slug: translatedPage.slug, untouchedSlug: untouchedPage.slug, refused }
})

test('refuses a field a translation takes from its origin', ({ state }) => {
    expect(state.refused.join('\n')).toContain('"layout" cannot be translated')
})

test('renders the translated page with its copied, changed and added images in order', async ({ page, state }) => {
    await page.goto(`/de${state.slug}`)

    const images = page.locator('.frame', { has: page.getByRole('heading', { name: 'Zwei Bilder' }) }).locator('img')
    await expect(images).toHaveCount(3)
    const alternatives = await images.evaluateAll((found) => found.map((image) => image.getAttribute('alt') ?? ''))

    expect(alternatives[0]).toMatch(/first image$/)
    expect(alternatives.slice(1)).toEqual(['zweites Bild', 'drittes Bild'])
})

test('shows the translation of the metadata media.json seeded', async ({ page, state }) => {
    await page.goto(`/de${state.slug}`)

    await expect(page.getByAltText('vom Namen gesät')).toHaveCount(1)
})

test('shows the metadata a test translated itself', async ({ page, state }) => {
    await page.goto(`/de${state.slug}`)

    await expect(page.getByAltText('ein Abzeichen')).toHaveCount(1)
})

test('renders a free copy in the language it was copied to', async ({ page, state }) => {
    await page.goto(`/de${state.slug}`)

    await expect(page.getByRole('heading', { name: 'Frei kopiert' })).toBeVisible()
})

test('reaches a page translated without any setter by the slug it answered', async ({ page, state }) => {
    const response = await page.goto(`/de${state.untouchedSlug}`)

    expect(response?.status()).toBe(200)
})
