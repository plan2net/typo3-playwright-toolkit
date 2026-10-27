import type { Frame, Page } from '@playwright/test'
import { getToolkitConfig } from './config.js'
import { RECORD_EDIT_ROUTE } from './http/record-edit.js'
import { DEFAULT_BACKEND_PATH } from './http/test-api.js'

const CONTENT_FRAME = '#typo3-contentIframe'
const EDIT_FORM = 'form[name="editform"]'

/**
 * Opens the backend's edit form of one record on a page from the `backendPage`
 * fixture, and answers the frame that holds it: the backend's content frame, or the
 * page itself where a core shows the form without the frame.
 */
export async function editRecord(page: Page, table: string, uid: number | string): Promise<Frame> {
    const config = getToolkitConfig()
    const backendPath = config.backendPath ?? DEFAULT_BACKEND_PATH
    await page.goto(`${config.testingURL}${backendPath}${RECORD_EDIT_ROUTE}?edit%5B${table}%5D%5B${uid}%5D=edit`)

    const shown = page.locator(`${CONTENT_FRAME}, ${EDIT_FORM}`).first()
    await shown.waitFor()
    if ('IFRAME' !== (await shown.evaluate((element) => element.tagName))) {
        return page.mainFrame()
    }

    const frame = await (await shown.elementHandle())?.contentFrame()
    if (!frame) {
        throw new Error(`[typo3-playwright-toolkit] The backend's content frame for ${table}:${uid} has no document.`)
    }
    await frame.locator(EDIT_FORM).waitFor()

    return frame
}
