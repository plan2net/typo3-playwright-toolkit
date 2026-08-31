import { Page } from '@playwright/test'
import { NestedFields } from '../types/common.js'
import { saveRecord, type RecordDataMap } from '../http/record-edit.js'
import { replayParentId, requireTestId, resolveRequestContext, type RequestContext } from './request-context.js'
import { toColumnValues } from './fields.js'
import { newRecordIdentifier } from './identifier.js'
import { mergeRecords, RelationSet } from './relations.js'
import type { ContentFields } from '../types/content-builder.js'
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
    private readonly relations = new RelationSet('pages', (column) => column in this.fields)
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

    /** A file reference on any column, with the reference's own fields. */
    withFileReference(column: string, fileUid: number, fields: ContentFields = {}): this {
        this.relations.withFileReference(column, fileUid, fields)
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
        const { columns, records } = this.relations.materialise({ pid: identifier, sys_language_uid: 0 })

        const data: RecordDataMap = {
            pages: { [identifier]: { ...this.pageFields(), ...columns, pid: parentId } },
        }
        mergeRecords(data, records)

        const saved = await saveRecord(this.page.request, context, {
            table: 'pages',
            identifier,
            target: Number(parentId),
            data,
        })

        // So its children keep it as their parent.
        context.replayFolder?.ownPages.add(String(saved.uid))

        return { id: String(saved.uid), slug: saved.slug ?? ((this.fields.slug as string) || '') }
    }

    async update(pageId: string): Promise<{ id: string; slug: string }> {
        const context = resolveRequestContext(this.page, this.requestContext)

        const { columns, records } = this.relations.materialise({ pid: pageId, sys_language_uid: 0 })
        const data: RecordDataMap = { pages: { [pageId]: { ...this.pageFields(), ...columns } } }
        mergeRecords(data, records)

        const saved = await saveRecord(this.page.request, context, {
            table: 'pages',
            identifier: pageId,
            target: Number(pageId),
            data,
        })

        return { id: pageId, slug: saved.slug ?? ((this.fields.slug as string) || '') }
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

    private pageFields(): Record<string, unknown> {
        return toColumnValues(this.fields)
    }
}
