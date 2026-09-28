import { Page, BrowserContext } from '@playwright/test'
import type { ScenarioSite } from '../sites/registry.js'

export interface PageWithTestId extends Page {
    testId?: string
}

export interface ContextWithTestId extends BrowserContext {
    testId?: string
    site?: ScenarioSite
}
