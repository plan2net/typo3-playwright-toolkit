import { getToolkitConfig } from '../config.js'
import { TRANSLATED_RECORD_HEADER, toolkitHeaders } from '../contract.js'
import { header, refuseWhatTypo3Rejected, savedRecord, type EditContext, type FormPoster } from './record-edit.js'

export const RECORD_COMMIT_ROUTE = '/record/commit'

export type TranslationMode = 'localize' | 'copyToLanguage'

export interface CommitContext extends EditContext {
    commandToken?: string
}

export interface RecordToTranslate {
    table: string
    uid: number
    language: number
    mode: TranslationMode
}

export interface TranslatedRecord {
    uid: number
    pid: number
    /** Per file column, in the order they show: position n is the copy of the origin's position n. */
    references: Record<string, number[]>
    /** The references did not fit a header, so `references` is empty. */
    truncated: boolean
    slug?: string
}

/** The command the backend's own "translate" link sends. */
export async function translateRecord(
    poster: FormPoster,
    context: CommitContext,
    record: RecordToTranslate,
): Promise<TranslatedRecord> {
    if (!context.commandToken) {
        throw new Error(
            '[typo3-playwright-toolkit] No route token for tce_db, so nothing can be translated. ' +
                'Translations need plan2net/playwright-toolkit 0.27.0 or newer.',
        )
    }

    const response = await poster.post(
        `${context.baseUrl}${context.backendPath}${RECORD_COMMIT_ROUTE}?token=${encodeURIComponent(context.commandToken)}`,
        {
            headers: toolkitHeaders(getToolkitConfig(), context.testId),
            multipart: { [`cmd[${record.table}][${record.uid}][${record.mode}]`]: String(record.language) },
            maxRedirects: 0,
        },
    )

    const headers = response.headers()
    refuseWhatTypo3Rejected(headers, record.table)

    const raw = header(headers, TRANSLATED_RECORD_HEADER)
    if (undefined === raw) {
        throw new Error(
            `[typo3-playwright-toolkit] TYPO3 created no translation of ${record.table} ${record.uid} ` +
                `in language ${record.language} (status ${response.status()}). Check that the site has that ` +
                'language and that the record has no translation in it yet.',
        )
    }

    const parsed = JSON.parse(raw) as {
        uid: number
        pid: number
        references?: Record<string, number[]>
        truncated?: boolean
    }
    const { slug } = savedRecord(headers)

    return {
        uid: parsed.uid,
        pid: parsed.pid,
        references: parsed.references ?? {},
        truncated: true === parsed.truncated,
        ...(undefined === slug ? {} : { slug }),
    }
}
