#!/usr/bin/env node
/**
 * Writes the .svg files the READMEs embed out of the .html sources.
 *
 * A source holds every responsive variant in one file behind container queries,
 * which GitHub cannot run: a README picks its variant with <picture media>, so
 * each one has to become a standalone file. The names are the READMEs' and
 * predate this script — the plain one is the variant a README shows by default.
 */
import * as fs from 'fs'
import * as path from 'path'
import { fileURLToPath } from 'url'

const here = path.dirname(fileURLToPath(import.meta.url))

const EXPORTS = {
    'one-header-one-database': { '': 'tablet', '-wide': 'wide', '-narrow': 'narrow' },
    'packages-overview': { '': 'tablet', '-wide': 'wide', '-narrow': 'narrow' },
    'full-test-run': { '': 'tablet', '-wide': 'wide', '-narrow': 'narrow' },
    'scenario-fan-out': { '': 'wide', '-narrow': 'narrow' },
    'backend-save-path': { '': 'tablet', '-wide': 'wide', '-narrow': 'narrow' },
    'where-things-run': { '': 'wide', '-narrow': 'narrow' },
}

function variant(source, name) {
    const open = new RegExp(`<svg class="${name}"`).exec(source)
    if (!open) {
        throw new Error(`no <svg class="${name}">`)
    }

    const end = source.indexOf('</svg>', open.index)

    return source.slice(open.index, end + '</svg>'.length)
}

// Without width and height GitHub renders the image at its own default rather
// than the drawing's aspect ratio.
// XML knows five entities; an HTML one like &middot; parses inside the source page and
// makes the exported file malformed, which GitHub renders as a missing image.
const XML_ENTITIES = /&(?!(amp|lt|gt|quot|apos|#\d+|#x[0-9a-fA-F]+);)[^;\s]*;/

function standalone(markup) {
    const stray = XML_ENTITIES.exec(markup)
    if (stray) {
        throw new Error(`${stray[0]} is not an XML entity — write the character itself`)
    }

    const [, width, height] = /viewBox="[\d.-]+ [\d.-]+ ([\d.]+) ([\d.]+)"/.exec(markup)

    return `<?xml version="1.0" encoding="UTF-8"?>\n${markup.replace(
        /^<svg class="\w+"/,
        `<svg width="${width}" height="${height}"`,
    )}\n`
}

let written = 0
for (const [diagram, files] of Object.entries(EXPORTS)) {
    const source = fs.readFileSync(path.join(here, `${diagram}.html`), 'utf-8')
    for (const [suffix, name] of Object.entries(files)) {
        const target = path.join(here, `${diagram}${suffix}.svg`)
        fs.writeFileSync(target, standalone(variant(source, name)))
        console.log(`${path.basename(target)} — ${name}`)
        written += 1
    }
}

console.log(`${written} file(s)`)
