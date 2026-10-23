import { defineScenario, expect } from '@plan2net/typo3-playwright-toolkit'

export const test = defineScenario(async ({ siteSettings }) => {
    await siteSettings({ 'e2e.look': 'wild' })

    return {}
})

test('renders the setting this test gave its site', async ({ page }) => {
    await page.goto('/')

    await expect(page.locator('[data-e2e="look"]')).toHaveText('look:wild')
})
