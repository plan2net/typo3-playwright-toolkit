import { CropConfig } from './common.js'
import type { RecordDataMap } from '../http/record-edit.js'

export interface ContentFields {
    [key: string]: string | number | CropConfig | boolean | undefined
}

export interface ContentBuilderInterface {
    readonly type: string
    getFields(): ContentFields
    /**
     * Extra records written in the same request — a file reference, a child
     * record. Point at the content element by `contentIdentifier`; DataHandler
     * substitutes the real uid once it has assigned one.
     */
    getAdditionalRecords?(contentIdentifier: string, pageId: string): RecordDataMap
}
