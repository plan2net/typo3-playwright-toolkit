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

/** What `batch()` needs of a queued element, whatever CType it builds. */
export interface QueuedContent {
    readonly identifier: string
    readonly targetPageId: string
    prepared(
        context: RequestContext,
        positionPid: string,
    ): { row: Record<string, unknown>; records: RecordDataMap }
}

class TypedContentBuilder<B extends ContentBuilderInterface = ContentBuilderInterface>
    implements QueuedContent
{
    readonly identifier = newRecordIdentifier()

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

    get targetPageId(): string {
        return this.pageId
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
        const pageId = replayParentId(context, this.pageId)
        const { row, records } = this.prepared(context, insertPosition(this.page, pageId))

        const data: RecordDataMap = { tt_content: { [this.identifier]: row } }
        mergeRecords(data, records)

        const saved = await saveRecord(this.page.request, context, {
            table: 'tt_content',
            identifier: this.identifier,
            target: Number(pageId),
            data,
        })

        lastElementOnPage.get(this.page)?.set(pageId, saved.uid)

        return { id: String(saved.uid) }
    }

    prepared(
        context: RequestContext,
        positionPid: string,
    ): { row: Record<string, unknown>; records: RecordDataMap } {
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

        const row: Record<string, unknown> = {
            pid: positionPid,
            sys_language_uid: 0,
            CType: fields.CType || this.builder.type,
            colPos: fields.colPos ?? 0,
            ...columnValues,
            ...inner.columns,
            ...outer.columns,
        }

        // Merged per table, not spread: a child table may be tt_content itself.
        const records: RecordDataMap = {}
        mergeRecords(records, inner.records)
        mergeRecords(records, outer.records)

        return { row, records }
    }
}

/**
 * One request for many elements, which saves a backend bootstrap each. Their own
 * rows go in first so the uids come back in the order they were queued, and each
 * element is positioned after the one before it: a positive pid means "insert at
 * the top", so an unchained batch lays the page out backwards.
 */
export async function saveBatch(
    page: Page,
    requestContext: Partial<RequestContext> | undefined,
    queued: QueuedContent[],
): Promise<Array<{ id: string }>> {
    if (0 === queued.length) {
        throw new Error('[typo3-playwright-toolkit] batch() was given nothing to build.')
    }

    const context = resolveRequestContext(page, requestContext)
    refuseASecondPage(queued)
    const pageId = replayParentId(context, queued[0].targetPageId)

    const rows: Record<string, Record<string, unknown>> = {}
    const children: RecordDataMap = {}
    let position = insertPosition(page, pageId)

    for (const element of queued) {
        const prepared = element.prepared(context, position)
        rows[element.identifier] = prepared.row
        mergeRecords(children, prepared.records)
        // Backwards only: DataHandler drops a position it cannot resolve yet, silently.
        position = `-${element.identifier}`
    }

    const data: RecordDataMap = { tt_content: rows }
    mergeRecords(data, children)

    const saved = await saveRecord(page.request, context, {
        table: 'tt_content',
        identifier: queued[0].identifier,
        target: Number(pageId),
        data,
    })

    const uids = saved.uids.slice(0, queued.length)
    if (uids.length < queued.length) {
        throw new Error(
            `[typo3-playwright-toolkit] The batch posted ${queued.length} elements and the ` +
                `redirect named ${uids.length} uids, so which element got which is unknown. ` +
                'Build them with create() instead.',
        )
    }

    lastElementOnPage.get(page)?.set(pageId, uids[uids.length - 1])

    return uids.map((uid) => ({ id: String(uid) }))
}

function refuseASecondPage(queued: QueuedContent[]): void {
    const first = queued[0].targetPageId
    const other = queued.find((element) => element.targetPageId !== first)
    if (undefined !== other) {
        throw new Error(
            `[typo3-playwright-toolkit] A batch builds on one and the same page, and this one ` +
                `mixes ${first} with ${other.targetPageId}. Elements are positioned after one ` +
                'another, which means nothing across pages. Use one batch per page.',
        )
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
