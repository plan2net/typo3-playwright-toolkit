import type { ContentBuilderInterface, ContentFields } from '../types/content-builder.js'
import type { RecordDataMap } from '../http/record-edit.js'
import { newRecordIdentifier } from './identifier.js'

export abstract class CoreContent implements ContentBuilderInterface {
    abstract readonly type: string

    protected fields: ContentFields = {}

    withHeader(header: string): this {
        return this.set('header', header)
    }

    withSubheader(subheader: string): this {
        return this.set('subheader', subheader)
    }

    /** 0 is "hidden", 1–5 are h1–h5 in a stock fluid_styled_content install. */
    withHeaderLayout(layout: number): this {
        return this.set('header_layout', layout)
    }

    withHeaderLink(link: string): this {
        return this.set('header_link', link)
    }

    withColPos(colPos: number): this {
        return this.set('colPos', colPos)
    }

    setHidden(hidden = true): this {
        return this.set('hidden', hidden)
    }

    /** Any column the typed setters do not cover, including your own. */
    withField(column: string, value: ContentFields[string]): this {
        return this.set(column, value)
    }

    getFields(): ContentFields {
        return { CType: this.type, ...this.fields }
    }

    protected set(column: string, value: ContentFields[string]): this {
        this.fields[column] = value

        return this
    }
}

abstract class MediaCoreContent extends CoreContent {
    /** The tt_content column the references hang off — `assets`, `image`, `media`. */
    protected abstract readonly mediaColumn: string

    private fileUids: number[] = []
    private identifiers: string[] = []

    withFile(fileUid: number): this {
        this.fileUids.push(fileUid)

        return this
    }

    withFiles(fileUids: number[]): this {
        this.fileUids.push(...fileUids)

        return this
    }

    override getFields(): ContentFields {
        const fields = super.getFields()
        if (this.fileUids.length > 0) {
            fields[this.mediaColumn] = this.referenceIdentifiers().join(',')
        }

        return fields
    }

    getAdditionalRecords(contentIdentifier: string, pageId: string): RecordDataMap {
        if (this.fileUids.length === 0) {
            return {}
        }

        const identifiers = this.referenceIdentifiers()
        const references: Record<string, Record<string, unknown>> = {}
        this.fileUids.forEach((fileUid, index) => {
            references[identifiers[index]] = {
                uid_local: fileUid,
                pid: pageId,
                tablenames: 'tt_content',
                fieldname: this.mediaColumn,
                sys_language_uid: 0,
                sorting_foreign: index + 1,
            }
        })

        return { sys_file_reference: references }
    }

    // Minted once: getFields() lists them in the parent column and
    // getAdditionalRecords() keys the children by them, and the two must agree.
    private referenceIdentifiers(): string[] {
        while (this.identifiers.length < this.fileUids.length) {
            this.identifiers.push(newRecordIdentifier())
        }

        return this.identifiers
    }
}

abstract class GalleryCoreContent extends MediaCoreContent {
    withColumns(imagecols: number): this {
        return this.set('imagecols', imagecols)
    }

    /** TCA `imageorient`: 0 above-center, 8 below-center, 17 in-text-right, … */
    withOrientation(imageorient: number): this {
        return this.set('imageorient', imageorient)
    }

    withImageSize(width?: number, height?: number): this {
        if (undefined !== width) {
            this.set('imagewidth', width)
        }
        if (undefined !== height) {
            this.set('imageheight', height)
        }

        return this
    }
}

export class HeaderContent extends CoreContent {
    readonly type = 'header'
}

export class TextContent extends CoreContent {
    readonly type = 'text'

    withBodyText(html: string): this {
        return this.set('bodytext', html)
    }
}

export class TextmediaContent extends GalleryCoreContent {
    readonly type = 'textmedia'

    protected readonly mediaColumn = 'assets'

    withBodyText(html: string): this {
        return this.set('bodytext', html)
    }
}

export class TextpicContent extends GalleryCoreContent {
    readonly type = 'textpic'

    protected readonly mediaColumn = 'image'

    withBodyText(html: string): this {
        return this.set('bodytext', html)
    }
}

export class ImageContent extends GalleryCoreContent {
    readonly type = 'image'

    protected readonly mediaColumn = 'image'
}

export class BulletsContent extends CoreContent {
    readonly type = 'bullets'

    /** One bullet per line — the column is a plain newline-separated text field. */
    withItems(items: string[]): this {
        return this.set('bodytext', items.join('\n'))
    }

    /** TCA `bullets_type`: 0 disc, 1 numbered, 2 none. */
    withBulletsType(bulletsType: number): this {
        return this.set('bullets_type', bulletsType)
    }
}

export class TableContent extends CoreContent {
    readonly type = 'table'

    withRows(rows: string[][]): this {
        const delimiter = String.fromCharCode(this.delimiter())

        return this.set('bodytext', rows.map((row) => row.join(delimiter)).join('\n')).set(
            'cols',
            rows[0]?.length ?? 0,
        )
    }

    withCaption(caption: string): this {
        return this.set('table_caption', caption)
    }

    /** 1 puts the first row in a thead; 0 none, 2 first column, 3 both. */
    withHeaderPosition(position: number): this {
        return this.set('table_header_position', position)
    }

    private delimiter(): number {
        const configured = this.fields.table_delimiter

        return 'number' === typeof configured ? configured : 124
    }
}

export class UploadsContent extends MediaCoreContent {
    readonly type = 'uploads'

    protected readonly mediaColumn = 'media'
}

export class HtmlContent extends CoreContent {
    readonly type = 'html'

    withHtml(html: string): this {
        return this.set('bodytext', html)
    }
}

export class DividerContent extends CoreContent {
    readonly type = 'div'
}

export class ShortcutContent extends CoreContent {
    readonly type = 'shortcut'

    /** Each entry is `tt_content_<uid>`, or a bare uid for tt_content. */
    withRecords(records: string[]): this {
        return this.set('records', records.join(','))
    }
}

export class MenuContent extends CoreContent {
    constructor(readonly type: string) {
        super()
    }

    withPages(pageUids: number[]): this {
        return this.set('pages', pageUids.join(','))
    }

    withSelectedCategories(categoryUids: number[]): this {
        return this.set('selected_categories', categoryUids.join(','))
    }
}

export const MENU_TYPES = [
    'menu_pages',
    'menu_subpages',
    'menu_sitemap',
    'menu_sitemap_pages',
    'menu_section',
    'menu_section_pages',
    'menu_abstract',
    'menu_recently_updated',
    'menu_related_pages',
    'menu_categorized_pages',
    'menu_categorized_content',
] as const

export type MenuType = (typeof MENU_TYPES)[number]

export interface CoreContentTypeMap extends Record<MenuType, MenuContent> {
    header: HeaderContent
    text: TextContent
    textmedia: TextmediaContent
    textpic: TextpicContent
    image: ImageContent
    bullets: BulletsContent
    table: TableContent
    uploads: UploadsContent
    html: HtmlContent
    div: DividerContent
    shortcut: ShortcutContent
}

/**
 * Consumers add their own CTypes by merging into this interface, which is what
 * keeps `configure` typed for them too:
 *
 * ```ts
 * declare module '@plan2net/typo3-playwright-toolkit' {
 *     interface ContentTypeMap { my_teaser: TeaserContent }
 * }
 * ```
 *
 * It starts empty so a key here can also replace a core one, the way
 * `registerContentTypes` replaces the builder behind it.
 */
// eslint-disable-next-line @typescript-eslint/no-empty-object-type
export interface ContentTypeMap {}

export type ContentTypeKey = keyof ContentTypeMap | keyof CoreContentTypeMap

export type ContentTypeFor<K extends ContentTypeKey> = K extends keyof ContentTypeMap
    ? ContentTypeMap[K]
    : K extends keyof CoreContentTypeMap
      ? CoreContentTypeMap[K]
      : never

export function coreContentTypes(): Record<string, new () => ContentBuilderInterface> {
    const types: Record<string, new () => ContentBuilderInterface> = {
        header: HeaderContent,
        text: TextContent,
        textmedia: TextmediaContent,
        textpic: TextpicContent,
        image: ImageContent,
        bullets: BulletsContent,
        table: TableContent,
        uploads: UploadsContent,
        html: HtmlContent,
        div: DividerContent,
        shortcut: ShortcutContent,
    }

    for (const menuType of MENU_TYPES) {
        types[menuType] = class extends MenuContent {
            constructor() {
                super(menuType)
            }
        }
    }

    return types
}
