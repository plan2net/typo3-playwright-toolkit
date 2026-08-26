#!/usr/bin/env node
/** Builds site/ — what GitHub Pages serves — from Main.dc.html. */
import * as fs from 'fs'
import * as path from 'path'
import { fileURLToPath } from 'url'

const here = path.dirname(fileURLToPath(import.meta.url))
const repoRoot = path.join(here, '..')
const outDir = path.join(repoRoot, 'site')

const SITE_URL = 'https://plan2net.github.io/typo3-playwright-toolkit/'
const TITLE = 'TYPO3 Playwright Toolkit'
const DESCRIPTION =
    'End-to-end tests for TYPO3 with a throwaway database per test file, content built through the real backend, and a signed link into every failure.'

const source = fs.readFileSync(path.join(here, 'Main.dc.html'), 'utf-8')

function between(text, open, close) {
    const start = text.indexOf(open)
    const end = text.indexOf(close, start)
    if (start < 0 || end < 0) {
        throw new Error(`build-docs: could not find ${open} … ${close}`)
    }

    return text.slice(start + open.length, end)
}

const helmet = between(source, '<helmet>', '</helmet>')
const bodySource = between(source, '</helmet>', '</x-dc>')
const componentSource = between(source, '">\nclass Component extends DCLogic {', '\n}\n</script>')

// Only its own fields are read, so the base class is not needed.
const Component = new Function(`class Component {${componentSource}}; return Component`)()
const component = new Component()
component.props = { accent: '#FF8700', reducedMotion: false }
component.systemStill = false
// A finished run, rather than one mid-animation.
component.state = { ...component.state, shown: component.runs.length + 2 }
const values = component.renderVals()

function escapeText(value) {
    return String(value).replace(/&(?![a-zA-Z#][a-zA-Z0-9]*;)/g, '&amp;').replace(/</g, '&lt;')
}

function escapeAttribute(value) {
    return escapeText(value).replace(/"/g, '&quot;')
}

function lookUp(expression, scope) {
    return expression.split('.').reduce((carrier, key) => carrier?.[key], scope)
}

function fillHoles(markup, scope) {
    return markup.replace(/\{\{\s*([\w.]+)\s*\}\}/g, (whole, expression) => {
        const value = lookUp(expression, scope)

        return undefined === value || 'function' === typeof value ? whole : escapeText(value)
    })
}

function enclosed(markup, tag, from) {
    const open = new RegExp(`<${tag}(\\s[^>]*)?>`, 'g')
    open.lastIndex = from
    const opening = open.exec(markup)
    if (!opening) {
        return undefined
    }

    const scan = new RegExp(`<${tag}(\\s[^>]*)?>|</${tag}>`, 'g')
    scan.lastIndex = opening.index + opening[0].length
    let depth = 1
    for (let match = scan.exec(markup); match; match = scan.exec(markup)) {
        depth += match[0].startsWith(`</${tag}`) ? -1 : 1
        if (0 === depth) {
            return {
                attributes: opening[1] ?? '',
                inner: markup.slice(opening.index + opening[0].length, match.index),
                start: opening.index,
                end: match.index + match[0].length,
            }
        }
    }

    throw new Error(`build-docs: unclosed <${tag}>`)
}

function attribute(attributes, name) {
    return new RegExp(`${name}="\\{\\{\\s*([\\w.]+)\\s*\\}\\}"`).exec(attributes)?.[1]
}

/** Both branches survive into the page — a button swaps them on click. */
const RUNTIME_BRANCHES = {
    running: 'data-rot="running"',
    paused: 'data-rot="paused" hidden',
    'p.isCopied': 'data-copy-state="done" hidden',
    'p.notCopied': 'data-copy-state="idle"',
}

function render(markup, scope) {
    let out = markup

    for (let region = enclosed(out, 'sc-for', 0); region; region = enclosed(out, 'sc-for', 0)) {
        const listName = attribute(region.attributes, 'list')
        const as = /as="(\w+)"/.exec(region.attributes)?.[1]
        const list = lookUp(listName, scope) ?? []
        const expanded = list
            .map((item) => render(region.inner, { ...scope, [as]: item }))
            .join('')
        out = out.slice(0, region.start) + expanded + out.slice(region.end)
    }

    for (let region = enclosed(out, 'sc-if', 0); region; region = enclosed(out, 'sc-if', 0)) {
        const name = attribute(region.attributes, 'value')
        const inner = render(region.inner, scope)
        const branch = RUNTIME_BRANCHES[name]
        const kept = branch
            ? `<span ${branch} style="display:inline-flex; align-items:center; gap:7px;">${inner}</span>`
            : lookUp(name, scope)
              ? inner
              : ''
        out = out.slice(0, region.start) + kept + out.slice(region.end)
    }

    return fillHoles(out, scope)
}

let body = render(bodySource, values)

body = body.replace(/onClick="\{\{ p\.onCopy \}\}"/g, 'data-copy')
const commands = values.packages.map((entry) => entry.cmd)
let copyIndex = 0
body = body.replace(/data-copy(?=[\s>])/g, () => `data-copy="${escapeAttribute(commands[copyIndex++])}"`)

body = body.replace(/onClick="\{\{ toggleRotation \}\}"/g, '')

const hoverRules = []
body = body.replace(/\s*style-hover="([^"]*)"/g, (whole, declarations) => {
    const name = `hv-${hoverRules.length}`
    hoverRules.push(`.${name}:hover { ${declarations} }`)

    return ` data-hv="${name}"`
})
body = body.replace(/<(\w+)([^>]*?)data-hv="([\w-]+)"/g, (whole, tag, rest, name) =>
    rest.includes('class="')
        ? `<${tag}${rest.replace('class="', `class="${name} `)}`
        : `<${tag}${rest} class="${name}"`,
)

const RUNTIME = `
const stillMedia = window.matchMedia('(prefers-reduced-motion: reduce)')

const claims = [...document.querySelectorAll('[data-claim]')]
const toggle = document.querySelector('[data-rotate-toggle]')
let current = 0
let rotating = true
let timer

function showClaim(index) {
    claims.forEach((claim, i) => {
        claim.style.opacity = i === index ? '1' : '0'
        claim.setAttribute('aria-hidden', i === index ? 'false' : 'true')
    })
}

function setBranch(button, name) {
    button.querySelectorAll('[data-rot]').forEach((part) => {
        part.hidden = part.dataset.rot !== name
    })
}

function rotate() {
    current = (current + 1) % claims.length
    showClaim(current)
}

function startRotating() {
    clearInterval(timer)
    if (stillMedia.matches || !rotating) {
        return
    }
    timer = setInterval(rotate, ${component.CLAIM_MS})
}

function applyMotionPreference() {
    if (!toggle) {
        return
    }
    toggle.hidden = stillMedia.matches
    if (stillMedia.matches) {
        current = 0
        showClaim(0)
    }
    startRotating()
}

if (toggle) {
    toggle.addEventListener('click', () => {
        rotating = !rotating
        setBranch(toggle, rotating ? 'running' : 'paused')
        startRotating()
    })
    stillMedia.addEventListener('change', applyMotionPreference)
    applyMotionPreference()
}

document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', () => {
        // Not awaited: a clipboard prompt would otherwise hold up the feedback.
        navigator.clipboard?.writeText(button.dataset.copy).catch(() => {})
        setBranch(button, 'done')
        clearTimeout(button.resetTimer)
        button.resetTimer = setTimeout(() => setBranch(button, 'idle'), 1600)
    })
})
document.querySelectorAll('[data-copy] [data-copy-state]').forEach((part) => {
    part.dataset.rot = part.dataset.copyState
})
`

function squeezeCss(css) {
    return css
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/\s+/g, ' ')
        .replace(/\s*([{}:;,])\s*/g, '$1')
        .replace(/;}/g, '}')
        .trim()
}

function squeezeJs(source) {
    return source
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .split('\n')
        .map((line) => line.replace(/\/\/.*$/, '').trim())
        .filter(Boolean)
        .join('\n')
}

/**
 * Newlines become a single space rather than nothing: between two inline elements
 * that space separates words, and dropping it runs them together.
 */
function squeezeHtml(markup) {
    const kept = []
    return markup
        .replace(/<pre[\s\S]*?<\/pre>/g, (block) => ` ${kept.push(block) - 1} `)
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/<style>([\s\S]*?)<\/style>/g, (whole, css) => `<style>${squeezeCss(css)}</style>`)
        .replace(/\s*\n\s*/g, ' ')
        .replace(/ {2,}/g, ' ')
        .replace(/style="([^"]*)"/g, (whole, rules) => `style="${rules.replace(/\s*([:;])\s*/g, '$1')}"`)
        .replace(/ (\d+) /g, (whole, index) => kept[Number(index)])
        .trim()
}

const head = squeezeHtml(`<meta charset="utf-8">
<title>${TITLE} — end-to-end tests for TYPO3</title>
<meta name="description" content="${escapeAttribute(DESCRIPTION)}">
<link rel="canonical" href="${SITE_URL}">
<link rel="icon" href="logo.svg" type="image/svg+xml">
<meta property="og:type" content="website">
<meta property="og:url" content="${SITE_URL}">
<meta property="og:title" content="${TITLE}">
<meta property="og:description" content="${escapeAttribute(DESCRIPTION)}">
<meta name="twitter:card" content="summary">
${helmet}
<style>[hidden]{display:none !important}${squeezeCss(hoverRules.join(''))}</style>`)

const page = `<!DOCTYPE html><html lang="en"><head>${head}</head><body>${squeezeHtml(
    body,
)}<script>${squeezeJs(RUNTIME)}</script></body></html>`

const leftovers = page.match(/\{\{[^}]*\}\}|<sc-(for|if)\b|style-hover=/g)
if (leftovers) {
    console.error(`build-docs: unresolved template syntax: ${[...new Set(leftovers)].join(', ')}`)
    process.exit(1)
}

fs.mkdirSync(outDir, { recursive: true })
fs.writeFileSync(path.join(outDir, 'index.html'), page)
fs.copyFileSync(path.join(here, 'logo.svg'), path.join(outDir, 'logo.svg'))

console.log(`site/index.html — ${Math.round(page.length / 1024)} KB`)
