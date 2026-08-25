/**
 * TYPO3 columns take scalars: a boolean is a 0/1 int column and a crop config is
 * a JSON *string* column, not a nested object. An undefined field is left out
 * rather than sent as null, which DataHandler would write.
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
        coerced[column] = 'object' === typeof value ? JSON.stringify(value) : value
    }

    return coerced
}
