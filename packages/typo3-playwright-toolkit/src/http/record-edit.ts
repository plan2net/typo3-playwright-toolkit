import { getToolkitConfig } from '../config.js'
import { RECORD_DIAGNOSTICS_HEADER, SAVED_RECORD_HEADER, toolkitHeaders } from '../contract.js'

/** Relative to the backend entry point, which a project may have moved. */
export const RECORD_EDIT_ROUTE = '/record/edit'

/** Shaped like a DataHandler datamap: table → identifier → fields. */
export type RecordDataMap = Record<string, Record<string, Record<string, unknown>>>

export interface FormResponse {
    status(): number
    headers(): Record<string, string>
    text(): Promise<string>
}

export interface FormPoster {
    post(
        url: string,
        options: { headers: Record<string, string>; multipart: Record<string, string>; maxRedirects: number },
    ): Promise<FormResponse>
}

export interface EditContext {
    baseUrl: string
    backendPath: string
    testId: string
    routeToken: string
}

export interface SavedRecord {
    uid: number
    /** Every uid the redirect named, in datamap order: one POST may carry a batch. */
    uids: number[]
    /** Absent where the table has no slug column. */
    slug?: string
    /** Per table, and not only the records that were asked for. */
    written?: Record<string, number>
}

/** saveRecord with the poster and context already bound. */
export function recordSaver(
    poster: FormPoster,
    context: EditContext,
): (record: RecordToSave) => Promise<SavedRecord> {
    return (record) => saveRecord(poster, context, record)
}

export interface RecordToSave {
    table: string
    /** A `NEW` prefix creates; anything else is a uid the form opens for editing. */
    identifier: string
    /** `edit[table][target]` — the parent page when creating, the record itself when updating. */
    target: number
    data: RecordDataMap
}

/**
 * The route the backend's own edit form posts to, so a TYPO3 release that changes
 * it, its request-token rules or its field names fails the suite rather than
 * passing quietly.
 */
export async function saveRecord(
    poster: FormPoster,
    context: EditContext,
    record: RecordToSave,
): Promise<SavedRecord> {
    if (!context.routeToken) {
        throw new Error(
            '[typo3-playwright-toolkit] No route token for record_edit. The backend refuses a POST ' +
                'without one and answers with a redirect to the login form. The session endpoint ' +
                'returns it — use a page from the toolkit fixtures.',
        )
    }

    const action = record.identifier.startsWith('NEW') ? 'new' : 'edit'
    const url =
        `${context.baseUrl}${context.backendPath}${RECORD_EDIT_ROUTE}` +
        `?edit%5B${record.table}%5D%5B${record.target}%5D=${action}` +
        `&token=${encodeURIComponent(context.routeToken)}`

    const response = await poster.post(url, {
        headers: toolkitHeaders(getToolkitConfig(), context.testId),
        multipart: formFields(record.data),
        // The new uid only exists in the redirect; following it loses it.
        maxRedirects: 0,
    })

    const headers = response.headers()
    const location = redirectTarget(response.status(), headers)
    if (undefined === location) {
        throw new Error(
            `[typo3-playwright-toolkit] ${record.table} did not save (status ${response.status()}). ` +
                `The backend answers a rejected save with a page, not a redirect: ` +
                `${(await response.text()).slice(0, 300)}`,
        )
    }

    refuseWhatTypo3Rejected(headers, record.table)

    const uids = uidsFrom(location, record.table)
    if (0 === uids.length) {
        throw new Error(
            `[typo3-playwright-toolkit] The save of ${record.table} redirected to no uid — ` +
                `a rejected request-token or session redirects to the login form. Location: ${location}`,
        )
    }

    const saved = { uid: uids[0], uids, ...savedRecord(headers) }
    warnAboutUnrequestedWrites(record, saved.written)

    return saved
}

/**
 * A slug change re-slugs every descendant page and writes a redirect for each, and
 * the save still answers like an ordinary success.
 */
function warnAboutUnrequestedWrites(record: RecordToSave, written?: Record<string, number>): void {
    if (undefined === written) {
        return
    }

    const requested = Object.fromEntries(
        Object.entries(record.data).map(([table, rows]) => [table, Object.keys(rows).length]),
    )
    const unrequested = Object.entries(written)
        .map(([table, count]) => ({ table, count, extra: count - (requested[table] ?? 0) }))
        .filter(({ extra }) => extra > 0)

    if (0 === unrequested.length) {
        return
    }

    const wrote = Object.values(written).reduce((sum, count) => sum + count, 0)
    const asked = Object.values(requested).reduce((sum: number, count: number) => sum + count, 0)
    const tables = unrequested
        .map(({ table, count, extra }) => `${table} ${count} (${extra} not requested)`)
        .join(', ')
    const hint = unrequested.some(({ table }) => 'sys_redirect' === table)
        ? '\n  A slug change cascades to every descendant page and writes a redirect for each.'
        : ''

    console.warn(
        `[typo3-playwright-toolkit] Saving ${record.table} wrote ${wrote} records for ${asked} requested.\n` +
            `  ${tables}${hint}`,
    )
}

/**
 * A refused child leaves the parent saved, so the request looks like a success
 * and the failure would only show up much later, somewhere else.
 */
function refuseWhatTypo3Rejected(headers: Record<string, string>, table: string): void {
    const raw = header(headers, RECORD_DIAGNOSTICS_HEADER)
    if (undefined === raw) {
        return
    }

    const { errors, count } = JSON.parse(raw) as {
        errors: Array<{ message: string; table: string }>
        count: number
    }
    const first = errors[0]
    const more = count > 1 ? `\n(and ${count - 1} more, see /typo3/test-api/errors)` : ''

    throw new Error(
        `[typo3-playwright-toolkit] TYPO3 refused a record while saving ${table}.\n` +
            `  table:   ${first.table}\n` +
            `  message: ${first.message}${more}`,
    )
}

/** `data[table][identifier][field]`, the names FormEngine gives its inputs. */
function formFields(data: RecordDataMap): Record<string, string> {
    const fields: Record<string, string> = {}

    for (const [table, rows] of Object.entries(data)) {
        for (const [identifier, row] of Object.entries(rows)) {
            for (const [column, value] of Object.entries(row)) {
                addField(fields, `data[${table}][${identifier}][${column}]`, value)
            }
        }
    }

    fields.doSave = '1'
    // Save without closing: that is what makes the controller redirect back to
    // the record it wrote, naming its uid.
    fields._savedok = '1'

    return fields
}

function addField(fields: Record<string, string>, name: string, value: unknown): void {
    if (undefined === value || null === value) {
        return
    }
    if ('object' === typeof value && !Array.isArray(value)) {
        for (const [key, nested] of Object.entries(value)) {
            addField(fields, `${name}[${key}]`, nested)
        }

        return
    }

    fields[name] = formValue(value)
}

function formValue(value: unknown): string {
    if ('string' === typeof value) {
        return value
    }
    if ('number' === typeof value) {
        return String(value)
    }
    if ('boolean' === typeof value) {
        return value ? '1' : '0'
    }

    return JSON.stringify(value) ?? ''
}

function redirectTarget(status: number, headers: Record<string, string>): string | undefined {
    if (status < 300 || status >= 400) {
        return undefined
    }

    return header(headers, 'location')
}

function header(headers: Record<string, string>, name: string): string | undefined {
    return headers[name.toLowerCase()] ?? headers[name]
}

/** A malformed envelope is not worth failing a save over: the uid still stands. */
function savedRecord(headers: Record<string, string>): { slug?: string; written?: Record<string, number> } {
    const value = header(headers, SAVED_RECORD_HEADER)
    if (undefined === value) {
        return {}
    }

    try {
        const parsed = JSON.parse(value) as { slug?: unknown; written?: unknown }

        return {
            ...('string' === typeof parsed.slug ? { slug: parsed.slug } : {}),
            ...(isCountMap(parsed.written) ? { written: parsed.written } : {}),
        }
    } catch {
        return {}
    }
}

function isCountMap(value: unknown): value is Record<string, number> {
    return (
        null !== value &&
        'object' === typeof value &&
        !Array.isArray(value) &&
        Object.values(value).every((count) => 'number' === typeof count)
    )
}

/** `edit[tt_content][42]=edit`, in whatever encoding the header carries. */
function uidsFrom(location: string, table: string): number[] {
    const matches = decodeURIComponent(location).matchAll(
        new RegExp(`edit\\[${table}\\]\\[(\\d+)\\]`, 'g'),
    )

    return [...matches]
        .map((match) => Number(match[1]))
        .filter((uid) => Number.isInteger(uid) && uid > 0)
}
