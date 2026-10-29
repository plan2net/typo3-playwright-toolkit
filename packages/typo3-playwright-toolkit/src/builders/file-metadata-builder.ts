import { Page } from '@playwright/test'
import { getToolkitConfig } from '../config.js'
import { translateRecord } from '../http/record-commit.js'
import { saveRecord } from '../http/record-edit.js'
import { resolveMetadataUid } from '../media/media-manifest.js'
import type { ContentFields } from '../types/content-builder.js'
import { toColumnValues } from './fields.js'
import { resolveRequestContext, type RequestContext } from './request-context.js'

export class FileMetadataBuilder {
    private language?: number
    private readonly fields: ContentFields = {}

    constructor(
        private readonly page: Page,
        private readonly name: string,
        private readonly requestContext?: Partial<RequestContext>,
    ) {
        if ('string' !== typeof name) {
            throw new Error(
                `[typo3-playwright-toolkit] fileMetadata() takes the name of a media fixture, not ${String(name)}: ` +
                    'a file uid does not say which metadata record belongs to it.',
            )
        }
    }

    withField(column: string, value: ContentFields[string]): this {
        this.fields[column] = value

        return this
    }

    /** Makes create() translate the metadata the way the backend does, then fill the translation. */
    translate(language: number): this {
        this.language = language

        return this
    }

    async create(): Promise<{ id: string }> {
        const context = resolveRequestContext(this.page, this.requestContext)
        let uid = resolveMetadataUid(getToolkitConfig(), this.name)

        if (undefined !== this.language) {
            uid = (await translateRecord(this.page.request, context, {
                table: 'sys_file_metadata',
                uid,
                language: this.language,
                mode: 'localize',
            })).uid
        }

        const row = toColumnValues(this.fields)
        if (Object.keys(row).length > 0) {
            await saveRecord(this.page.request, context, {
                table: 'sys_file_metadata',
                identifier: String(uid),
                target: uid,
                data: { sys_file_metadata: { [String(uid)]: row } },
            })
        }

        return { id: String(uid) }
    }
}
