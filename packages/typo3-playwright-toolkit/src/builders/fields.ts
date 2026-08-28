import type { NestedFields } from '../types/common.js'

export interface CropRectangle {
    x: number
    y: number
    width: number
    height: number
}

const WHOLE_IMAGE: CropRectangle = { x: 0, y: 0, width: 1, height: 1 }

/**
 * The `crop` column of a file reference, which holds JSON text. Without an area it
 * keeps the whole image, which is what a ratio on its own means.
 */
export function imageCrop(
    options: { ratio?: string; area?: CropRectangle; focus?: CropRectangle | null } = {},
): string {
    return JSON.stringify({
        default: {
            cropArea: options.area ?? WHOLE_IMAGE,
            selectedRatio: options.ratio ?? 'NaN',
            focusArea: options.focus ?? null,
        },
    })
}

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
