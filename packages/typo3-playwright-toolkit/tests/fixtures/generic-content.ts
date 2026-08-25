import type { ContentBuilderInterface, ContentFields } from '#src/types/content-builder.js'

export class GenericTextContent implements ContentBuilderInterface {
    readonly type = 'generic_text'
    private fields: ContentFields = {
        header: '',
        bodytext: '',
        colPos: 0,
        hidden: false,
    }

    withHeader(text: string): this {
        this.fields.header = text
        return this
    }

    withBodyText(html: string): this {
        this.fields.bodytext = html
        return this
    }

    getFields(): ContentFields {
        return { CType: this.type, ...this.fields }
    }
}
