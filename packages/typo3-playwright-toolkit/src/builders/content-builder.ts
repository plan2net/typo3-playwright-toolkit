import { Page } from '@playwright/test'
import { ContentBuilderInterface } from '../types/content-builder.js'
import { createContent } from './content-factory.js'
import type { ContentTypeFor, ContentTypeKey } from './core-content.js'
import { saveRecord } from '../http/record-edit.js'
import { coerceFields } from './fields.js'
import { replayParentId, resolveRequestContext, type RequestContext } from './request-context.js'
import { newRecordIdentifier } from './identifier.js'

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

    async create(): Promise<{ id: string }> {
        const context = resolveRequestContext(this.page, this.requestContext)
        const identifier = newRecordIdentifier()
        const pageId = replayParentId(context, this.pageId)
        const fields = this.builder.getFields()

        // CType and colPos are set below; an empty string means "the type did not
        // set it".
        const own = Object.fromEntries(
            Object.entries(fields).filter(
                ([column, value]) => value !== '' && !['CType', 'colPos'].includes(column),
            ),
        )

        const record: Record<string, unknown> = {
            pid: pageId,
            sys_language_uid: 0,
            CType: fields.CType || this.builder.type,
            colPos: fields.colPos ?? 0,
            ...coerceFields(own),
        }

        const uid = await saveRecord(this.page.request, context, {
            table: 'tt_content',
            identifier: identifier,
            target: Number(pageId),
            data: {
                tt_content: { [identifier]: record },
                ...(this.builder.getAdditionalRecords?.(identifier, pageId) ?? {}),
            },
        })

        return { id: String(uid) }
    }
}
