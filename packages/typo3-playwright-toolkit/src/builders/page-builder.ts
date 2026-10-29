import { Page } from '@playwright/test'
import { NestedFields } from '../types/common.js'
import { refusalOf, saveRecord, type RecordDataMap } from '../http/record-edit.js'
import { replayParentId, requireTestId, resolveRequestContext, type RequestContext } from './request-context.js'
import { ownValues, toColumnValues } from './fields.js'
import { newRecordIdentifier } from './identifier.js'
import { keepCopies, mergeRecords, RelationSet, type CopiedReferenceEdit } from './relations.js'
import { translateRecord } from '../http/record-commit.js'
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

/**
 * Creation only. On an update they would unhide a hidden page, turn a shortcut back
 * into a standard page and empty columns the caller never named.
 */
const CREATE_DEFAULTS: Fields = {
    doktype: 1,
    pid: 1,
    hidden: false,
}

/** TYPO3 hides what it translates, for an editor to check first. */
export const TRANSLATION_DEFAULTS: Fields = { hidden: false }

export class PageBuilder {
    protected fields: Fields = {}

    private page: Page
    private optedOutOfFormRules = false
    private translation?: { origin: number; language: number }
    private readonly copiedEdits: CopiedReferenceEdit[] = []
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

    withoutFormRules(): this {
        this.optedOutOfFormRules = true
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
    withFileReference(column: string, file: string | number, fields: ContentFields = {}): this {
        this.relations.withFileReference(column, file, fields)
        return this
    }

    /**
     * Makes create() translate the page the way the backend does, connected to it.
     * The other setters then fill the translation.
     */
    translate(pageId: number | string, language: number): this {
        this.translation = { origin: Number(pageId), language }

        return this
    }

    /** Changes the reference the translation copied from that position (from 0) of the origin's column. */
    withCopiedFileReference(column: string, position: number, fields: ContentFields): this {
        this.copiedEdits.push({ column, position, fields })

        return this
    }

    /** A name core ships, or a number if your project registered its own doktype. */
    withDoktype(type: Doktype | number): this {
        this.fields.doktype = 'number' === typeof type ? type : DOKTYPES[type]

        return this
    }

    /** A string too, since that is what create() reports. */
    atParentId(id: number | string): this {
        this.fields.pid = Number(id)
        return this
    }

    async create(): Promise<{ id: string; slug: string }> {
        if (undefined !== this.translation) {
            return this.createTranslation(this.translation)
        }

        const context = resolveRequestContext(this.page, this.requestContext)
        const fields = { ...CREATE_DEFAULTS, pid: context.rootPageId ?? CREATE_DEFAULTS.pid, ...this.fields }
        const identifier = newRecordIdentifier()
        const parentId = replayParentId(context, String(Number(fields.pid)))
        const { columns, records } = this.relations.materialise({ pid: identifier, sys_language_uid: 0 })

        const data: RecordDataMap = {
            pages: { [identifier]: { ...toColumnValues(fields), ...columns, pid: parentId } },
        }
        mergeRecords(data, records)

        const saved = await saveRecord(this.page.request, context, {
            table: 'pages',
            identifier,
            target: Number(parentId),
            data,
            withoutFormRules: this.optedOutOfFormRules,
        })
        this.rememberSlug(context, saved.slug)

        // So its children keep it as their parent.
        context.replayFolder?.ownPages.add(String(saved.uid))

        return { id: String(saved.uid), slug: saved.slug ?? ((this.fields.slug as string) || '') }
    }

    /** Saves the page as create() does, for a test that expects the backend to refuse it: TYPO3's reasons. */
    refusal(): Promise<string[]> {
        return refusalOf(() => this.create())
    }

    async update(pageId: string): Promise<{ id: string; slug: string }> {
        const context = resolveRequestContext(this.page, this.requestContext)

        const { columns, records } = this.relations.materialise({ pid: pageId, sys_language_uid: 0 })
        const data: RecordDataMap = { pages: { [pageId]: { ...toColumnValues(this.fields), ...columns } } }
        mergeRecords(data, records)

        const saved = await saveRecord(this.page.request, context, {
            table: 'pages',
            identifier: pageId,
            target: Number(pageId),
            data,
            withoutFormRules: this.optedOutOfFormRules,
        })

        return { id: pageId, slug: saved.slug ?? ((this.fields.slug as string) || '') }
    }

    private async createTranslation(translation: { origin: number; language: number }): Promise<{ id: string; slug: string }> {
        if ('pid' in this.fields || 'doktype' in this.fields) {
            throw new Error(
                '[typo3-playwright-toolkit] A page translation takes its parent and its type from the page it ' +
                    'translates. Leave out atParentId() and withDoktype().',
            )
        }

        const context = resolveRequestContext(this.page, this.requestContext)
        const translated = await translateRecord(this.page.request, context, {
            table: 'pages',
            uid: translation.origin,
            language: translation.language,
            mode: 'localize',
        })
        const id = String(translated.uid)

        const { columns, records } = keepCopies(
            this.relations.materialise({ pid: translated.uid, sys_language_uid: translation.language }),
            translated,
            this.copiedEdits,
        )
        const row = { ...toColumnValues({ ...TRANSLATION_DEFAULTS, ...this.fields }), ...columns }
        const data: RecordDataMap = { pages: { [id]: { ...row, l10n_state: ownValues(Object.keys(row)) } } }
        mergeRecords(data, records)

        const saved = await saveRecord(this.page.request, context, {
            table: 'pages',
            identifier: id,
            target: translated.uid,
            data,
            withoutFormRules: this.optedOutOfFormRules,
        })
        this.rememberSlug(context, saved.slug)

        return { id, slug: saved.slug ?? translated.slug ?? '' }
    }

    private rememberSlug(context: RequestContext, stored: string | undefined): void {
        const requested = this.fields.slug
        if ('string' !== typeof requested || '' === requested || !context.usedSlugs || undefined === stored) {
            return
        }

        if (stored !== requested && context.usedSlugs.has(requested)) {
            throw new Error(
                `[typo3-playwright-toolkit] This scenario already created a page with the slug "${requested}". ` +
                    `TYPO3 stored this one as "${stored}", and every test reading it would land on the first page. ` +
                    'Give them distinct slugs.',
            )
        }

        context.usedSlugs.add(stored)
        context.usedSlugs.add(requested)
    }
}
