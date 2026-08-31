import { NestedFields } from './common.js'
import type { RelationOutput, RelationOwner } from '../builders/relations.js'

export interface ContentFields {
    [key: string]: string | number | NestedFields | boolean | undefined
}

export interface ContentBuilderInterface {
    readonly type: string
    getFields(): ContentFields
    /** Beside getFields, never through it: an override would drop relations silently. */
    getRelations?(owner: RelationOwner): RelationOutput
}
