import { Page } from '@playwright/test'
import { CropConfig, NestedFields } from '../types/common.js'
import { saveRecord, type RecordDataMap } from '../http/record-edit.js'
import { replayParentId, requireTestId, resolveRequestContext, type RequestContext } from './request-context.js'
import { coerceFields } from './fields.js'
import { newRecordIdentifier } from './identifier.js'
import { getToolkitConfig } from '../config.js'

interface Fields {
    [key: string]: string | number | boolean | undefined | NestedFields
}

const DOKTYPES = {
    page: 1,
    link: 3,
    shortcut: 4,
    'backend-user-section': 6,
    mountpoint: 7,
    spacer: 199,
    folder: 254,
} as const

export type Doktype = keyof typeof DOKTYPES

const DEFAULT_CROP =
    '{"default":{"cropArea":{"x":0,"y":0,"width":1,"height":1},"selectedRatio":"NaN","focusArea":null}}'

export class PageBuilder {
    protected fields: Fields = {
        doktype: 1,
        shortcut_mode: 0,
        pid: 1,
        hidden: false,
        layout: 0,
        subtitle: '',
    }

    private page: Page
    private imageFileId?: number
    private imageCropConfig?: CropConfig
    private mediaIdentifierValue?: string
    private readonly requestContext?: Partial<RequestContext>

    constructor(page: Page, requestContext?: Partial<RequestContext>) {
        this.page = page
        this.requestContext = requestContext
    }

    withTitle(title: string): this {
        this.fields.title = title
        return this
    }

    /** Any other TCA column — doktype, layout, backend_layout, shortcut_mode, … */
    withField(column: string, value: string | number | boolean): this {
        this.fields[column] = value
        return this
    }

    /** The test ID is appended so two runs never claim the same URL. */
    withSlug(slug: string): this {
        const testId = requireTestId(this.page, this.requestContext?.testId)

        let normalizedSlug = slug.startsWith('/') ? slug : `/${slug}`
        normalizedSlug = normalizedSlug.endsWith('/') ? normalizedSlug.slice(0, -1) : normalizedSlug

        // One database, so nothing to keep apart, and the slugs stay exportable.
        this.fields.slug = getToolkitConfig().replay
            ? normalizedSlug
            : `${normalizedSlug}-${testId.toLowerCase()}`
        return this
    }

    withExistingImage(fileId: number): this {
        this.imageFileId = fileId
        return this
    }

    withImageCropFocus(cropConfig: CropConfig): this {
        this.imageCropConfig = cropConfig
        return this
    }

    /** A name core ships, or a number if your project registered its own doktype. */
    withDoktype(type: Doktype | number): this {
        this.fields.doktype = 'number' === typeof type ? type : DOKTYPES[type]

        return this
    }

    atParentId(id: number): this {
        this.fields.pid = id
        return this
    }

    async create(): Promise<{ id: string; slug: string }> {
        const context = resolveRequestContext(this.page, this.requestContext)
        this.claimSlug(context)
        const identifier = newRecordIdentifier()
        const parentId = replayParentId(context, String(Number(this.fields.pid)))

        const uid = await saveRecord(this.page.request, context, {
            table: 'pages',
            identifier,
            target: Number(parentId),
            data: {
                pages: { [identifier]: { ...this.pageFields(), pid: parentId } },
                ...this.mediaRecords(identifier),
            },
        })

        // So its children keep it as their parent.
        context.replayFolder?.ownPages.add(String(uid))

        return { id: String(uid), slug: (this.fields.slug as string) || '' }
    }

    async update(pageId: string): Promise<{ id: string; slug: string }> {
        const context = resolveRequestContext(this.page, this.requestContext)

        await saveRecord(this.page.request, context, {
            table: 'pages',
            identifier: pageId,
            target: Number(pageId),
            data: {
                pages: { [pageId]: this.pageFields() },
                ...this.mediaRecords(pageId),
            },
        })

        return { id: pageId, slug: (this.fields.slug as string) || '' }
    }

    // TYPO3 would store the repeat elsewhere while create() still reports this slug.
    private claimSlug(context: RequestContext): void {
        const slug = this.fields.slug
        if ('string' !== typeof slug || '' === slug || !context.usedSlugs) {
            return
        }

        if (context.usedSlugs.has(slug)) {
            throw new Error(
                `[typo3-playwright-toolkit] This scenario already created a page with the slug "${slug}". ` +
                    'TYPO3 would store the second one under a different path, and every test reading it ' +
                    'would land on the first page. Give them distinct slugs.',
            )
        }

        context.usedSlugs.add(slug)
    }

    /**
     * A second record the page's own `media` field lists, which is what makes
     * DataHandler link it once the page has a uid.
     */
    private mediaRecords(pageIdentifier: string): RecordDataMap {
        if (!this.imageFileId) {
            return {}
        }

        return {
            sys_file_reference: {
                [this.mediaIdentifier()]: {
                    uid_local: this.imageFileId,
                    pid: pageIdentifier,
                    tablenames: 'pages',
                    fieldname: 'media',
                    hidden: 0,
                    sys_language_uid: 0,
                    alternative: '',
                    description: '',
                    title: '',
                    crop: this.imageCropConfig ? JSON.stringify(this.imageCropConfig) : DEFAULT_CROP,
                },
            },
        }
    }

    private pageFields(): Record<string, unknown> {
        const fields = coerceFields(this.fields)

        if (this.imageFileId) {
            fields.media = this.mediaIdentifier()
        }

        return fields
    }

    // Minted once: pageFields() lists it in `media` and mediaRecords() keys the
    // reference by it, and the two must agree.
    private mediaIdentifier(): string {
        this.mediaIdentifierValue ??= newRecordIdentifier()

        return this.mediaIdentifierValue
    }
}
