#!/usr/bin/env node
/** Builds site/ — what GitHub Pages serves — from landing-page.dc.html. */
import * as fs from 'fs'
import * as path from 'path'
import { fileURLToPath } from 'url'

const here = path.dirname(fileURLToPath(import.meta.url))
const repoRoot = path.join(here, '..')
const outDir = path.join(repoRoot, 'site')

const SITE_URL = 'https://plan2net.github.io/typo3-playwright-toolkit/'
const REPO_URL = 'https://github.com/plan2net/typo3-playwright-toolkit'
const TITLE = 'TYPO3 Playwright Toolkit'
const DESCRIPTION =
    'End-to-end tests for TYPO3 CMS: every test file gets its own throwaway database, content is built through the real backend, and every failure keeps a signed link into its backend.'

const source = fs.readFileSync(path.join(here, 'landing-page.dc.html'), 'utf-8')

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

// A code block that scrolls has to be reachable by keyboard.
body = body.replace(/<pre /g, '<pre tabindex="0" ')

// Both states become classes: an inline style would outrank the :hover rule.
const hoverRules = []
body = body.replace(/<(\w+)((?:[^>"]|"[^"]*")*?\sstyle-hover="[^"]*"(?:[^>"]|"[^"]*")*)>/g, (whole, tag, attributes) => {
    const hover = /\sstyle-hover="([^"]*)"/.exec(attributes)?.[1] ?? ''
    const base = /\sstyle="([^"]*)"/.exec(attributes)?.[1] ?? ''
    const name = `hv-${hoverRules.length}`

    if (base) {
        hoverRules.push(`.${name}{${base}}`)
    }
    hoverRules.push(`.${name}:hover{${hover}}`)

    const rest = attributes.replace(/\sstyle-hover="[^"]*"/, '').replace(/\sstyle="[^"]*"/, '')

    return rest.includes('class="')
        ? `<${tag}${rest.replace('class="', `class="${name} `)}>`
        : `<${tag}${rest} class="${name}">`
})

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

// Prints once, on first view. visibility, so the terminal never changes height.
const runSteps = [...document.querySelectorAll('[data-run-line]'), ...document.querySelectorAll('[data-run-summary]')]

if (runSteps.length > 0 && !stillMedia.matches) {
    const pending = []
    const reveal = (step) => {
        step.style.visibility = 'visible'
    }

    runSteps.forEach((step) => {
        step.style.visibility = 'hidden'
    })

    const play = () => {
        runSteps.forEach((step, index) => {
            pending.push(setTimeout(() => reveal(step), index * 420))
        })
    }

    const watcher = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (entry.isIntersecting) {
                watcher.disconnect()
                play()
            }
        }
    }, { threshold: 0.35 })
    watcher.observe(runSteps[0].closest('div'))

    // Turned on part-way through: stop and show the finished run.
    stillMedia.addEventListener('change', () => {
        if (stillMedia.matches) {
            watcher.disconnect()
            pending.forEach(clearTimeout)
            runSteps.forEach(reveal)
        }
    })
}

function announce(button, message) {
    const status = button.closest('li')?.querySelector('[data-copy-status]')
    if (status) {
        status.textContent = message
    }
}

document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        // Awaited: the tick has to mean the clipboard actually took it.
        try {
            await navigator.clipboard.writeText(button.dataset.copy)
        } catch {
            announce(button, 'Copying failed. Select the command and copy it yourself.')
            return
        }
        announce(button, 'Command copied')
        setBranch(button, 'done')
        clearTimeout(button.resetTimer)
        button.resetTimer = setTimeout(() => {
            setBranch(button, 'idle')
            announce(button, '')
        }, 1600)
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

// Newlines become a space, not nothing: between inline elements it separates words.
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

const version = JSON.parse(
    fs.readFileSync(path.join(repoRoot, 'packages/typo3-playwright-toolkit/package.json'), 'utf-8'),
).version

// What an answer engine reads instead of the prose.
const structuredData = {
    '@context': 'https://schema.org',
    '@type': 'SoftwareApplication',
    name: TITLE,
    alternateName: 'plan2net/playwright-toolkit',
    description: DESCRIPTION,
    applicationCategory: 'DeveloperApplication',
    applicationSubCategory: 'Testing framework',
    operatingSystem: 'Linux, macOS, Windows',
    url: SITE_URL,
    softwareVersion: version,
    codeRepository: REPO_URL,
    license: 'https://spdx.org/licenses/GPL-2.0-or-later.html',
    programmingLanguage: ['TypeScript', 'PHP'],
    softwareRequirements: 'TYPO3 CMS 11.5, 12.4, 13.4 or 14.3; PHP 8.1 or newer; DDEV',
    isAccessibleForFree: true,
    offers: { '@type': 'Offer', price: '0', priceCurrency: 'EUR' },
    author: { '@type': 'Organization', name: 'plan2net', url: 'https://www.plan2.net/' },
    keywords: 'TYPO3, Playwright, end-to-end testing, DDEV, test database, visual regression',
}

const head =
    squeezeHtml(`<meta charset="utf-8">
<title>${TITLE} — end-to-end tests for TYPO3 CMS</title>
<meta name="description" content="${escapeAttribute(DESCRIPTION)}">
<link rel="canonical" href="${SITE_URL}">
<link rel="icon" href="logo.svg" type="image/svg+xml">
<meta name="theme-color" content="#FF8700">
<meta name="author" content="plan2net">
<meta property="og:type" content="website">
<meta property="og:site_name" content="${TITLE}">
<meta property="og:locale" content="en">
<meta property="og:url" content="${SITE_URL}">
<meta property="og:title" content="${TITLE}">
<meta property="og:description" content="${escapeAttribute(DESCRIPTION)}">
<meta property="og:image" content="${SITE_URL}og-image.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="${escapeAttribute(TITLE)}: end-to-end tests for TYPO3 CMS">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="${TITLE}">
<meta name="twitter:description" content="${escapeAttribute(DESCRIPTION)}">
<meta name="twitter:image" content="${SITE_URL}og-image.png">
${helmet}
<style>[hidden]{display:none !important}${squeezeCss(hoverRules.join(''))}</style>`) +
    `<script type="application/ld+json">${JSON.stringify(structuredData)}</script>`

let page = `<!DOCTYPE html><html lang="en"><head>${head}</head><body>${squeezeHtml(
    body,
)}<script>${squeezeJs(RUNTIME)}</script></body></html>`

/**
 * Inlined, not linked: an SVG loaded through <img> never fetches the self-hosted
 * fonts. Injected after squeezeHtml, whose <pre> placeholder pass rewrites any
 * " 123 " it finds and would corrupt path data — so squeeze the markup here.
 */
page = page.replace(/<div class="figure"([^>]*) data-diagram="([\w-]+)"([^>]*)><\/div>/g, (whole, before, name, after) => {
    const source = fs.readFileSync(path.join(repoRoot, `diagrams/${name}.html`), 'utf-8')
    const variants = source.match(/<svg[\s\S]*?<\/svg>/g) ?? []
    if (variants.length === 0) {
        console.error(`build-docs: no <svg> in diagrams/${name}.html`)
        process.exit(1)
    }

    const markup = variants
        .join('')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/>\s+</g, '><')

    return `<div class="figure"${before}${after}>${markup}</div>`
})

const leftovers = page.match(/\{\{[^}]*\}\}|<sc-(for|if)\b|style-hover=/g)
if (leftovers) {
    console.error(`build-docs: unresolved template syntax: ${[...new Set(leftovers)].join(', ')}`)
    process.exit(1)
}

// llmstxt.org
const llmsTxt = `# ${TITLE}

> ${DESCRIPTION}

Three packages that only work together: a DDEV add-on (the db-test database service
and the ddev playwright commands), a TYPO3 extension (\`plan2net/playwright-toolkit\`,
which clones the databases and hands out a backend session), and an npm package
(\`@plan2net/typo3-playwright-toolkit\`, the Playwright fixtures and content builders).

Version ${version}. GPL-2.0-or-later. TYPO3 CMS 11.5, 12.4, 13.4 and 14.3 on PHP 8.1
to 8.4, with drivers for MariaDB, MySQL, PostgreSQL and SQLite.

What is unusual about it: every spec file runs against its own database, cloned from a
prepared template in about 27 ms, so files cannot break each other. Content is created
through TYPO3's own backend save route rather than from SQL fixtures, which means a
renamed field fails a test instead of passing against data that no longer matches the
data model, and two people adding tests never edit the same fixture file. A failed test keeps
its database, and \`ddev playwright-inspect\` prints a signed link that logs you into
the TYPO3 backend of that exact run.

## Documentation

- [README](${REPO_URL}#readme): install, setup and how to write a test
- [Wire contract](${REPO_URL}/blob/main/CONTRACT.md): the test-ID header chain the packages agree on
- [DDEV add-on](${REPO_URL}/tree/main/packages/ddev-typo3-playwright-toolkit): commands, flags, database service
- [TYPO3 extension](${REPO_URL}/tree/main/packages/playwright-toolkit): endpoints, settings, testing host
- [npm package](${REPO_URL}/tree/main/packages/typo3-playwright-toolkit): defineScenario, builders, screenshots, axe
- [Example project](${REPO_URL}/tree/main/tests/e2e/consumer): a working TYPO3 project CI reinstalls on every push
- [Changelog](${REPO_URL}/blob/main/CHANGELOG.md)
`

const robotsTxt = `User-agent: *
Allow: /

Sitemap: ${SITE_URL}sitemap.xml
`

const sitemapXml = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>${SITE_URL}</loc><changefreq>weekly</changefreq><priority>1.0</priority></url>
</urlset>
`

fs.mkdirSync(outDir, { recursive: true })
fs.writeFileSync(path.join(outDir, 'index.html'), page)
fs.writeFileSync(path.join(outDir, 'llms.txt'), llmsTxt)
fs.writeFileSync(path.join(outDir, 'robots.txt'), robotsTxt)
fs.writeFileSync(path.join(outDir, 'sitemap.xml'), sitemapXml)
fs.copyFileSync(path.join(here, 'logo.svg'), path.join(outDir, 'logo.svg'))
fs.copyFileSync(path.join(here, 'og-image.png'), path.join(outDir, 'og-image.png'))
// The licence ships beside them: the OFL asks for it wherever the fonts go.
fs.mkdirSync(path.join(outDir, 'fonts'), { recursive: true })
for (const file of ['caveat.woff2', 'open-sans.woff2', 'source-code-pro.woff2', 'OFL.txt']) {
    fs.copyFileSync(path.join(here, 'fonts', file), path.join(outDir, 'fonts', file))
}

// The page must fetch nothing from anywhere else: a stylesheet, a font or an image
// from a third party sends every visitor's IP address there before it renders.
const offSite = page.match(/(?:src|href)="https?:\/\/[^"]*"|url\(\s*['"]?https?:/g) ?? []
const requests = offSite.filter((reference) => !/^href=/.test(reference))
if (requests.length > 0) {
    console.error(`build-docs: the page would request ${requests.join(', ')}`)
    process.exit(1)
}

console.log(`site/index.html — ${Math.round(page.length / 1024)} KB, plus llms.txt, robots.txt, sitemap.xml`)
