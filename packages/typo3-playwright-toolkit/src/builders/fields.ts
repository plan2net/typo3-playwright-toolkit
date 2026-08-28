import type { NestedFields } from '../types/common.js'

export type FlexFormValues = Record<string, string | number | boolean>

/**
 * A flexform column, for `withField('pi_flexform', flexForm({...}))`. Plain values
 * go to `sDEF`; name a sheet per group where the structure has several.
 */
export function flexForm(values: FlexFormValues): NestedFields
export function flexForm(sheets: Record<string, FlexFormValues>): NestedFields
export function flexForm(
    input: FlexFormValues | Record<string, FlexFormValues>,
): NestedFields {
    const sheets = Object.values(input).every((value) => 'object' === typeof value)
        ? (input as Record<string, FlexFormValues>)
        : { sDEF: input as FlexFormValues }

    const data: NestedFields = {}
    for (const [sheet, values] of Object.entries(sheets)) {
        const fields: NestedFields = {}
        for (const [name, value] of Object.entries(values)) {
            fields[name] = { vDEF: value }
        }
        data[sheet] = { lDEF: fields }
    }

    return { data }
}

/**
 * A boolean is a 0/1 int column. An undefined field is left out rather than sent
 * as null, which DataHandler would write.
 */
export function coerceFields(fields: Record<string, unknown>): Record<string, unknown> {
    const coerced: Record<string, unknown> = {}

    for (const [column, value] of Object.entries(fields)) {
        if (value === undefined) {
            continue
        }
        if ('boolean' === typeof value) {
            coerced[column] = value ? 1 : 0
            continue
        }
        coerced[column] = value
    }

    return coerced
}
