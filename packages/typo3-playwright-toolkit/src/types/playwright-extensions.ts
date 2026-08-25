import { Page, BrowserContext } from '@playwright/test'

export interface PageWithTestId extends Page {
    testId?: string
}

export interface ContextWithTestId extends BrowserContext {
    testId?: string
}
