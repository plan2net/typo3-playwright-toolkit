export {
    defineScenario,
    type ScenarioBuilders,
    type ScenarioFixtures,
    type SetupTools,
} from './scenario.js'
export { expect } from '@playwright/test'
export {
    defineToolkitConfig,
    getToolkitConfig,
    type ToolkitConfig,
    type ToolkitConfigInput,
    type ToolkitPaths,
    type ToolkitScreenshotConfig,
    type ContentTypeConstructor,
} from './config.js'
export { defineBasePlaywrightConfig, type BasePlaywrightOverrides } from './playwright/base-config.js'

export { ContentBuilder } from './builders/content-builder.js'
export { PageBuilder } from './builders/page-builder.js'
export {
    CoreContent,
    HeaderContent,
    TextContent,
    TextmediaContent,
    TextpicContent,
    ImageContent,
    BulletsContent,
    TableContent,
    UploadsContent,
    HtmlContent,
    DividerContent,
    ShortcutContent,
    MenuContent,
    type ContentTypeMap,
    type CoreContentTypeMap,
} from './builders/core-content.js'
export type { RecordDataMap, RecordToSave } from './http/record-edit.js'
export { newRecordIdentifier } from './builders/identifier.js'
export { flexForm, imageCrop, imageCrops } from './builders/fields.js'

export { expectScreenshot, waitForAnimations } from './checks/screenshot.js'
export {
    runAccessibilityScan,
    scanAccessibility,
    DEFAULT_SCAN_TAGS,
    type AccessibilityScanOptions,
} from './checks/accessibility.js'
export { CspVerifier, type CspMode, type CspViolation } from './checks/csp.js'

export { TEST_ID_HEADER, TEST_ID_PATTERN } from './contract.js'
export { RUN_ID_ENV, RUN_ID_PATTERN } from './state/run-id.js'
export type { CropConfig, NestedFields } from './types/common.js'
export type { ContentFields, ContentBuilderInterface } from './types/content-builder.js'
export type { PageWithTestId, ContextWithTestId } from './types/playwright-extensions.js'
