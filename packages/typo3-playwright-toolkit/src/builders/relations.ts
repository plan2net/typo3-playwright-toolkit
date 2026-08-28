import type { RecordDataMap } from '../http/record-edit.js'
import type { ContentFields } from '../types/content-builder.js'
import { newRecordIdentifier } from './identifier.js'

export interface RelationOwner {
    pid: string | number
    sys_language_uid: string | number
}

export interface RelationOutput {
    columns: Record<string, string>
    records: RecordDataMap
}

const WIRED_COLUMNS = ['uid_local', 'uid_foreign', 'tablenames', 'fieldname', 'sorting_foreign']

interface FileReference {
    column: string
    identifier: string
    fileUid: number
    fields: ContentFields
}

interface Child {
    column: string
    table: string
    identifier: string
    child: ChildRecord
}

export class ChildRecord {
    private readonly fields: ContentFields = {}

    withField(column: string, value: ContentFields[string]): this {
        this.fields[column] = value

        return this
    }

    materialise(owner: RelationOwner): Record<string, unknown> {
        return { pid: owner.pid, sys_language_uid: owner.sys_language_uid, ...this.fields }
    }
}

export class RelationSet {
    private readonly references: FileReference[] = []
    private readonly children: Child[] = []

    constructor(private readonly ownerTable: string) {}

    withFileReference(column: string, fileUid: number, fields: ContentFields = {}): this {
        this.refuseWiredColumns(fields)
        this.references.push({ column, identifier: newRecordIdentifier(), fileUid, fields })

        return this
    }

    withFileReferences(column: string, fileUids: number[], fields: ContentFields = {}): this {
        fileUids.forEach((fileUid) => this.withFileReference(column, fileUid, fields))

        return this
    }

    withChild(column: string, table: string, configure: (child: ChildRecord) => void): this {
        const child = new ChildRecord()
        configure(child)
        this.children.push({ column, table, identifier: newRecordIdentifier(), child })

        return this
    }

    materialise(owner: RelationOwner): RelationOutput {
        const tokens: Record<string, string[]> = {}
        const records: RecordDataMap = {}

        this.references.forEach(({ column, identifier, fileUid, fields }) => {
            const ordered = (tokens[column] ??= [])
            ordered.push(identifier)
            const rows = (records.sys_file_reference ??= {})
            rows[identifier] = {
                uid_local: fileUid,
                pid: owner.pid,
                tablenames: this.ownerTable,
                fieldname: column,
                sys_language_uid: owner.sys_language_uid,
                sorting_foreign: ordered.length,
                ...fields,
            }
        })

        this.children.forEach(({ column, table, identifier, child }) => {
            ;(tokens[column] ??= []).push(identifier)
            const rows = (records[table] ??= {})
            rows[identifier] = child.materialise(owner)
        })

        const columns = Object.fromEntries(
            Object.entries(tokens).map(([column, ordered]) => [column, ordered.join(',')]),
        )

        return { columns, records }
    }

    private refuseWiredColumns(fields: ContentFields): void {
        const wired = Object.keys(fields).filter((column) => WIRED_COLUMNS.includes(column))
        if (wired.length > 0) {
            throw new Error(
                `[typo3-playwright-toolkit] ${wired.join(', ')} cannot be set on a relation — ` +
                    'the toolkit writes it, and a value of your own would point the row ' +
                    'somewhere the parent column does not.',
            )
        }
    }
}
