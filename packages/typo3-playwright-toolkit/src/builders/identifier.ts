// Hex only, like StringUtility::getUniqueId(): RelationHandler reads a list
// entry containing `_` as table_uid and never resolves it back to the record.
export function newRecordIdentifier(): string {
    const time = Date.now().toString(16)
    const entropy = Math.floor(Math.random() * 0xffffffff).toString(16)

    return `NEW${time}${entropy.padStart(8, '0')}`
}
