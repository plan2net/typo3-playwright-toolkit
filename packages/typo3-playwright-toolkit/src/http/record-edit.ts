import { getToolkitConfig } from '../config.js'
import { toolkitHeaders } from '../contract.js'

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

/** Binds a poster and context so a scenario setup can write its own tables. */
export function recordSaver(
    poster: FormPoster,
    context: EditContext,
): (record: RecordToSave) => Promise<number> {
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
): Promise<number> {
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

    const location = redirectTarget(response)
    if (undefined === location) {
        throw new Error(
            `[typo3-playwright-toolkit] ${record.table} did not save (status ${response.status()}). ` +
                `The backend answers a rejected save with a page, not a redirect: ` +
                `${(await response.text()).slice(0, 300)}`,
        )
    }

    const uid = uidFrom(location, record.table)
    if (undefined === uid) {
        throw new Error(
            `[typo3-playwright-toolkit] The save of ${record.table} redirected to no uid — ` +
                `a rejected request-token or session redirects to the login form. Location: ${location}`,
        )
    }

    return uid
}

/** `data[table][identifier][field]`, the names FormEngine gives its inputs. */
function formFields(data: RecordDataMap): Record<string, string> {
    const fields: Record<string, string> = {}

    for (const [table, rows] of Object.entries(data)) {
        for (const [identifier, row] of Object.entries(rows)) {
            for (const [column, value] of Object.entries(row)) {
                if (undefined === value || null === value) {
                    continue
                }
                fields[`data[${table}][${identifier}][${column}]`] = formValue(value)
            }
        }
    }

    fields.doSave = '1'
    // Save without closing: that is what makes the controller redirect back to
    // the record it wrote, naming its uid.
    fields._savedok = '1'

    return fields
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

function redirectTarget(response: FormResponse): string | undefined {
    if (response.status() < 300 || response.status() >= 400) {
        return undefined
    }

    const headers = response.headers()

    return headers.location ?? headers.Location
}

/** `edit[tt_content][42]=edit`, in whatever encoding the header carries. */
function uidFrom(location: string, table: string): number | undefined {
    const match = decodeURIComponent(location).match(new RegExp(`edit\\[${table}\\]\\[(\\d+)\\]`))
    if (null === match) {
        return undefined
    }

    const uid = Number(match[1])

    return Number.isInteger(uid) && uid > 0 ? uid : undefined
}
