/** A flexform column: the form posts one input per value, not one per column. */
export interface NestedFields {
    [key: string]: string | number | boolean | NestedFields
}

export type CropConfig = Record<
    string,
    {
        cropArea: {
            x: number
            y: number
            width: number
            height: number
        }
        selectedRatio: string
        /** null is what TYPO3 itself writes when no focus area is set. */
        focusArea: {
            x: number
            y: number
            width: number
            height: number
        } | null
    }
>
