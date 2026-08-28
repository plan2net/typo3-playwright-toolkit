import type { ContentBuilderInterface, ContentFields } from '../types/content-builder.js'
import {
    RelationSet,
    type ChildRecord,
    type RelationOutput,
    type RelationOwner,
} from './relations.js'

const HEADING_LEVELS = { h1: 1, h2: 2, h3: 3, h4: 4, h5: 5, hidden: 100 } as const
const BULLET_TYPES = { bullets: 0, numbers: 1, definition: 2 } as const
const TABLE_HEADER_POSITIONS = { none: 0, top: 1, left: 2, both: 3 } as const
const IMAGE_ORIENTATIONS = {
    'above-center': 0,
    'above-right': 1,
    'above-left': 2,
    'below-center': 8,
    'below-right': 9,
    'below-left': 10,
    'in-text-right': 17,
    'in-text-left': 18,
    'beside-text-right': 25,
    'beside-text-left': 26,
} as const

export type HeadingLevel = keyof typeof HEADING_LEVELS
export type BulletType = keyof typeof BULLET_TYPES
export type TableHeaderPosition = keyof typeof TABLE_HEADER_POSITIONS
export type ImageOrientation = keyof typeof IMAGE_ORIENTATIONS

export abstract class CoreContent implements ContentBuilderInterface {
    abstract readonly type: string

    protected fields: ContentFields = {}
    protected readonly relations = new RelationSet(
        'tt_content',
        (column) => column in this.fields,
    )

    withHeader(header: string, level?: HeadingLevel): this {
        this.set('header', header)

        return undefined === level ? this : this.withHeaderLayout(HEADING_LEVELS[level])
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

    withFileReference(column: string, fileUid: number, fields: ContentFields = {}): this {
        this.relations.withFileReference(column, fileUid, fields)

        return this
    }

    withFileReferences(column: string, fileUids: number[], fields: ContentFields = {}): this {
        this.relations.withFileReferences(column, fileUids, fields)

        return this
    }

    withChild(column: string, table: string, configure: (child: ChildRecord) => void): this {
        this.relations.withChild(column, table, configure)

        return this
    }

    withChildren<T>(
        column: string,
        table: string,
        items: T[],
        configure: (child: ChildRecord, item: T) => void,
    ): this {
        this.relations.withChildren(column, table, items, configure)

        return this
    }

    getRelations(owner: RelationOwner): RelationOutput {
        return this.relations.materialise(owner)
    }

    getFields(): ContentFields {
        return { CType: this.type, ...this.fields }
    }

    protected set(column: string, value: ContentFields[string]): this {
        this.relations.refuseColumnHeld(column)
        this.fields[column] = value

        return this
    }
}

abstract class MediaCoreContent extends CoreContent {
    /** The tt_content column the references hang off — `assets`, `image`, `media`. */
    protected abstract readonly mediaColumn: string

    withFile(fileUid: number): this {
        return this.withFileReference(this.mediaColumn, fileUid)
    }

    withFiles(fileUids: number[]): this {
        return this.withFileReferences(this.mediaColumn, fileUids)
    }
}

abstract class GalleryCoreContent extends MediaCoreContent {
    withColumns(imagecols: number): this {
        return this.set('imagecols', imagecols)
    }

    withOrientation(orientation: ImageOrientation): this {
        return this.set('imageorient', IMAGE_ORIENTATIONS[orientation])
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

    withBulletsType(type: BulletType): this {
        return this.set('bullets_type', BULLET_TYPES[type])
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

    withHeaderPosition(position: TableHeaderPosition): this {
        return this.set('table_header_position', TABLE_HEADER_POSITIONS[position])
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
