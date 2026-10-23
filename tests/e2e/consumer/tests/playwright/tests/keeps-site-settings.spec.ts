import { defineScenario, expect } from '@plan2net/typo3-playwright-toolkit'

export const test = defineScenario()

test('renders the site setting another test overrode for itself', async ({ page }) => {
    await page.goto('/')

    await expect(page.locator('[data-e2e="look"]')).toHaveText('look:calm')
})
