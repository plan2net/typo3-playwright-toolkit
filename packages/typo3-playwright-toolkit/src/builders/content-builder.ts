import { Page } from '@playwright/test'
import { ContentBuilderInterface, type ContentFields } from '../types/content-builder.js'
import { createContent } from './content-factory.js'
import type { ContentTypeFor, ContentTypeKey } from './core-content.js'
import { saveRecord, type RecordDataMap } from '../http/record-edit.js'
import { mergeRecords, RelationSet, type ChildRecord } from './relations.js'
import { toColumnValues } from './fields.js'
import { replayParentId, resolveRequestContext, type RequestContext } from './request-context.js'
import { newRecordIdentifier } from './identifier.js'

const lastElementOnPage = new WeakMap<Page, Map<string, number>>()

export class ContentBuilder {
    private page: Page
    private pageId = ''
    private readonly requestContext?: Partial<RequestContext>

    constructor(page: Page, requestContext?: Partial<RequestContext>) {
        this.page = page
        this.requestContext = requestContext
    }

    onPage(pageId: string): this {
        this.pageId = pageId
        return this
    }

    /**
     * A key of ContentTypeMap hands `.configure()` that builder's own type, so
     * the setters autocomplete; any other string falls back to the bare interface.
     */
    ofType<K extends ContentTypeKey>(type: K): TypedContentBuilder<ContentTypeFor<K>>
    ofType(type: string): TypedContentBuilder<ContentBuilderInterface>
    ofType(type: string): TypedContentBuilder<ContentBuilderInterface> {
        if (!this.pageId) {
            throw new Error(
                `[typo3-playwright-toolkit] Call .onPage(pageId) before .ofType('${type}') — ` +
                    'without a page the content element has nowhere to live.',
            )
        }

        return new TypedContentBuilder(this.page, this.pageId, type, this.requestContext)
    }
}

class TypedContentBuilder<B extends ContentBuilderInterface = ContentBuilderInterface> {
    private builder: ContentBuilderInterface
    private readonly fields: ContentFields = {}
    private readonly relations = new RelationSet('tt_content', (column) => column in this.fields)

    constructor(
        private page: Page,
        private pageId: string,
        type: string,
        private requestContext?: Partial<RequestContext>,
    ) {
        this.builder = createContent(type)
    }

    configure(fn: (builder: B) => void): this {
        fn(this.builder as B)
        return this
    }

    withField(column: string, value: ContentFields[string]): this {
        this.fields[column] = value

        return this
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

    async create(): Promise<{ id: string }> {
        const context = resolveRequestContext(this.page, this.requestContext)
        const identifier = newRecordIdentifier()
        const pageId = replayParentId(context, this.pageId)
        const fields = this.builder.getFields()

        // Kept out so the explicit CType and colPos below survive the spread.
        const own = Object.fromEntries(
            Object.entries(fields).filter(([column]) => !['CType', 'colPos'].includes(column)),
        )

        const columnValues = toColumnValues({ ...own, ...this.fields })
        // Not the record's own pid, which carries the -uid positioning the element.
        const owner = {
            pid: (columnValues.pid as string | number) ?? pageId,
            sys_language_uid: (columnValues.sys_language_uid as string | number) ?? 0,
        }
        const inner = this.builder.getRelations?.(owner) ?? { columns: {}, records: {} }
        const outer = this.relations.materialise(owner)
        refuseSharedColumns(inner.columns, outer.columns)

        const record: Record<string, unknown> = {
            pid: insertPosition(this.page, pageId),
            sys_language_uid: 0,
            CType: fields.CType || this.builder.type,
            colPos: fields.colPos ?? 0,
            ...columnValues,
            ...inner.columns,
            ...outer.columns,
        }

        // Merged per table, not spread: a child table may be tt_content itself.
        const data: RecordDataMap = { tt_content: { [identifier]: record } }
        mergeRecords(data, this.builder.getAdditionalRecords?.(identifier, pageId) ?? {})
        mergeRecords(data, inner.records)
        mergeRecords(data, outer.records)

        const saved = await saveRecord(this.page.request, context, {
            table: 'tt_content',
            identifier: identifier,
            target: Number(pageId),
            data,
        })

        lastElementOnPage.get(this.page)?.set(pageId, saved.uid)

        return { id: String(saved.uid) }
    }
}

function refuseSharedColumns(
    inner: Record<string, string>,
    outer: Record<string, string>,
): void {
    const shared = Object.keys(outer).filter((column) => column in inner)
    if (shared.length > 0) {
        throw new Error(
            `[typo3-playwright-toolkit] The column ${shared.join(' and ')} is filled twice — ` +
                'once inside configure() and once on the builder. Nothing decides which of ' +
                'the two comes first, so the records would end up in a random order. Put ' +
                'them all in one place.',
        )
    }
}

/** Keyed by the page: `builders.content()` hands out a fresh builder per call. */
function insertPosition(page: Page, pageId: string): string {
    let created = lastElementOnPage.get(page)
    if (undefined === created) {
        created = new Map()
        lastElementOnPage.set(page, created)
    }

    const previous = created.get(pageId)

    return undefined === previous ? pageId : `-${previous}`
}
