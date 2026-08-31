/** A flexform column: the form posts one input per value, not one per column. */
export interface NestedFields {
    [key: string]: string | number | boolean | NestedFields
}
