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

function mergeRecords(into: RecordDataMap, from: RecordDataMap): void {
    for (const [table, rows] of Object.entries(from)) {
        Object.assign((into[table] ??= {}), rows)
    }
}

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
    private readonly relations: RelationSet

    constructor(table: string) {
        this.relations = new RelationSet(table)
    }

    withField(column: string, value: ContentFields[string]): this {
        if (this.relations.holds(column)) {
            throw new Error(
                `[typo3-playwright-toolkit] The column "${column}" already holds a relation, ` +
                    'so setting it with withField would decide what the test builds by ' +
                    'whichever call ran last.',
            )
        }
        this.fields[column] = value

        return this
    }

    withFileReference(column: string, fileUid: number, fields: ContentFields = {}): this {
        this.refuseSetColumn(column)
        this.relations.withFileReference(column, fileUid, fields)

        return this
    }

    withChild(column: string, table: string, configure: (child: ChildRecord) => void): this {
        this.refuseSetColumn(column)
        this.relations.withChild(column, table, configure)

        return this
    }

    /** Relations inherit from the merged row, so a pid the child sets reaches them. */
    materialise(owner: RelationOwner): { row: Record<string, unknown>; records: RecordDataMap } {
        const row = { pid: owner.pid, sys_language_uid: owner.sys_language_uid, ...this.fields }
        const { columns, records } = this.relations.materialise(row)

        return { row: { ...row, ...columns }, records }
    }

    private refuseSetColumn(column: string): void {
        if (column in this.fields) {
            throw new Error(
                `[typo3-playwright-toolkit] The column "${column}" is already set with ` +
                    'withField, so a relation on it would decide what the test builds by ' +
                    'whichever call ran last.',
            )
        }
    }
}

export class RelationSet {
    private readonly references: FileReference[] = []
    private readonly children: Child[] = []
    private readonly claims = new Map<string, string>()

    constructor(private readonly ownerTable: string) {}

    withFileReference(column: string, fileUid: number, fields: ContentFields = {}): this {
        this.refuseWiredColumns(fields)
        this.claim(column, 'sys_file_reference')
        this.references.push({ column, identifier: newRecordIdentifier(), fileUid, fields })

        return this
    }

    withFileReferences(column: string, fileUids: number[], fields: ContentFields = {}): this {
        fileUids.forEach((fileUid) => this.withFileReference(column, fileUid, fields))

        return this
    }

    withChild(column: string, table: string, configure: (child: ChildRecord) => void): this {
        this.claim(column, table)
        const child = new ChildRecord(table)
        configure(child)
        this.children.push({ column, table, identifier: newRecordIdentifier(), child })

        return this
    }

    withChildren<T>(
        column: string,
        table: string,
        items: T[],
        configure: (child: ChildRecord, item: T) => void,
    ): this {
        items.forEach((item) => this.withChild(column, table, (child) => configure(child, item)))

        return this
    }

    holds(column: string): boolean {
        return this.claims.has(column)
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
            const { row, records: nested } = child.materialise(owner)
            ;(records[table] ??= {})[identifier] = row
            mergeRecords(records, nested)
        })

        const columns = Object.fromEntries(
            Object.entries(tokens).map(([column, ordered]) => [column, ordered.join(',')]),
        )

        return { columns, records }
    }

    private claim(column: string, table: string): void {
        const claimed = this.claims.get(column)
        if (undefined !== claimed && claimed !== table) {
            throw new Error(
                `[typo3-playwright-toolkit] The column "${column}" already holds ${claimed} ` +
                    `records and cannot also hold ${table} ones. A column resolves against a ` +
                    'single foreign_table, so the others would be written and never found.',
            )
        }

        this.claims.set(column, table)
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
