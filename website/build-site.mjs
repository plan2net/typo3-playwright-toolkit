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
    'End-to-end tests for TYPO3 CMS: every test file gets its own throwaway database, content is built through the real backend, and its own images rather than images shared with every other test.'

const rawSource = fs.readFileSync(path.join(here, 'landing-page.dc.html'), 'utf-8')

/**
 * Applied in one pass, so a German replacement is never rescanned. Every key must be
 * found: a wording change on the English page fails the build rather than leaving an
 * English sentence in the German one.
 */
const GERMAN = JSON.parse(fs.readFileSync(path.join(here, 'de.json'), 'utf-8'))

const LOCALES = [
    {
        code: 'en',
        directory: '',
        assetPrefix: '',
        diagramSuffix: '',
        dictionary: undefined,
        titleSuffix: 'end-to-end tests for TYPO3 CMS',
        description: DESCRIPTION,
        imageAlt: `${TITLE}: end-to-end tests for TYPO3 CMS`,
        copied: 'Copied to the clipboard',
        copyFailed: 'Copying failed. Select the text and copy it yourself.',
    },
    {
        code: 'de',
        directory: 'de',
        assetPrefix: '../',
        diagramSuffix: '.de',
        dictionary: GERMAN,
        titleSuffix: 'End-to-End-Tests für TYPO3 CMS',
        description:
            'End-to-End-Tests für TYPO3 CMS: Jede Testdatei bekommt ihre eigene Wegwerf-Datenbank, die Inhalte entstehen im echten Backend, und ihre eigenen Bilder statt Bilder, die sich alle Tests teilen.',
        imageAlt: `${TITLE}: End-to-End-Tests für TYPO3 CMS`,
        copied: 'In die Zwischenablage kopiert',
        copyFailed: 'Kopieren hat nicht geklappt. Markieren Sie den Text und kopieren Sie ihn selbst.',
    },
]

function escapeRegExp(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

function translate(text, dictionary) {
    if (!dictionary) {
        return text
    }

    const entries = new Map(dictionary)
    // So a short key cannot claim part of a longer one.
    const keys = [...entries.keys()].sort((a, b) => b.length - a.length)
    const seen = new Set()
    // Any whitespace matches any other, so a key need not reproduce the page's
    // non-breaking spaces and line breaks.
    const pattern = keys.map((key) => `(${escapeRegExp(key).replace(/\s+/g, '\\s+')})`).join('|')
    const out = text.replace(new RegExp(pattern, 'g'), (...match) => {
        const index = match.slice(1, 1 + keys.length).findIndex((group) => undefined !== group)
        seen.add(keys[index])

        return entries.get(keys[index])
    })

    const missing = keys.filter((key) => !seen.has(key))
    if (missing.length > 0) {
        console.error('build-docs: de.json has entries the page does not contain:')
        for (const key of missing) {
            console.error(`  ${JSON.stringify(key.slice(0, 90))}`)
        }
        process.exit(1)
    }

    return out
}

function between(text, open, close) {
    const start = text.indexOf(open)
    const end = text.indexOf(close, start)
    if (start < 0 || end < 0) {
        throw new Error(`build-docs: could not find ${open} … ${close}`)
    }

    return text.slice(start + open.length, end)
}

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

function runtime(locale, claimMs) {
    return `
const stillMedia = window.matchMedia('(prefers-reduced-motion: reduce)')

const claims = [...document.querySelectorAll('[data-claim]')]
const toggle = document.querySelector('[data-rotate-toggle]')
let current = 0
let rotating = true
let timer

// visibility rather than aria-hidden: AT honours it natively, and one property
// cannot fall out of step with the other.
function showClaim(index) {
    claims.forEach((claim, i) => {
        claim.style.opacity = i === index ? '1' : '0'
        claim.style.visibility = i === index ? 'visible' : 'hidden'
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
    timer = setInterval(rotate, ${claimMs})
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
    const status = button.parentElement?.querySelector('[data-copy-status]')
    if (status) {
        status.textContent = message
    }
}

// A block copies itself, so the code on the page and the copied text cannot drift.
function copyText(button) {
    const target = button.dataset.copyTarget

    return target ? document.getElementById(target).textContent : button.dataset.copy
}

document.querySelectorAll('[data-copy], [data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
        // Awaited: the tick has to mean the clipboard actually took it.
        try {
            await navigator.clipboard.writeText(copyText(button))
        } catch {
            announce(button, ${JSON.stringify(locale.copyFailed)})
            return
        }
        announce(button, ${JSON.stringify(locale.copied)})
        setBranch(button, 'done')
        clearTimeout(button.resetTimer)
        button.resetTimer = setTimeout(() => {
            setBranch(button, 'idle')
            announce(button, '')
        }, 1600)
    })
})
document.querySelectorAll('[data-copy-state]').forEach((part) => {
    part.dataset.rot = part.dataset.copyState
})
`
}

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
// NUL as the placeholder delimiter, written as an escape rather than as the byte: any
// delimiter markup can contain also matches what the page really says, and a space
// matched the 0 in `margin:0 0 14px`.
function squeezeHtml(markup) {
    const kept = []
    return markup
        .replace(/<pre[\s\S]*?<\/pre>/g, (block) => `\0${kept.push(block) - 1}\0`)
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/<style>([\s\S]*?)<\/style>/g, (whole, css) => `<style>${squeezeCss(css)}</style>`)
        .replace(/\s*\n\s*/g, ' ')
        .replace(/ {2,}/g, ' ')
        .replace(/style="([^"]*)"/g, (whole, rules) => `style="${rules.replace(/\s*([:;])\s*/g, '$1')}"`)
        .replace(/\0(\d+)\0/g, (whole, index) => kept[Number(index)])
        .trim()
}

const version = JSON.parse(
    fs.readFileSync(path.join(repoRoot, 'packages/typo3-playwright-toolkit/package.json'), 'utf-8'),
).version

function pageUrl(locale) {
    return locale.directory ? `${SITE_URL}${locale.directory}/` : SITE_URL
}

/** What an answer engine reads instead of the prose. */
function structuredData(locale) {
    return {
        '@context': 'https://schema.org',
        '@type': 'SoftwareApplication',
        name: TITLE,
        alternateName: 'plan2net/playwright-toolkit',
        description: locale.description,
        applicationCategory: 'DeveloperApplication',
        applicationSubCategory: 'Testing framework',
        operatingSystem: 'Linux, macOS, Windows',
        url: pageUrl(locale),
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
}

function buildPage(locale) {
    const source = translate(rawSource, locale.dictionary)

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

    let body = render(bodySource, values)

    body = body.replace(/onClick="\{\{ p\.onCopy \}\}"/g, 'data-copy')
    const commands = values.packages.map((entry) => entry.cmd)
    let copyIndex = 0
    body = body.replace(/data-copy(?=[\s>])/g, () => {
        const command = commands[copyIndex++]
        if (!command) {
            console.error('build-docs: more package copy buttons than package commands')
            process.exit(1)
        }

        return `data-copy="${escapeAttribute(command)}"`
    })

    // Only the package buttons are positional; the hand-written ones name their own command.
    body = body.replace(/data-copy-text=/g, 'data-copy=')

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

    const alternates = LOCALES.map(
        (other) => `<link rel="alternate" hreflang="${other.code}" href="${pageUrl(other)}">`,
    ).join('')

    const head =
        squeezeHtml(`<meta charset="utf-8">
<title>${TITLE} — ${locale.titleSuffix}</title>
<meta name="description" content="${escapeAttribute(locale.description)}">
<link rel="canonical" href="${pageUrl(locale)}">
${alternates}<link rel="alternate" hreflang="x-default" href="${SITE_URL}">
<link rel="icon" href="logo.svg" type="image/svg+xml">
<meta name="theme-color" content="#FF8700">
<meta name="author" content="plan2net">
<meta property="og:type" content="website">
<meta property="og:site_name" content="${TITLE}">
<meta property="og:locale" content="${locale.code}">
<meta property="og:url" content="${pageUrl(locale)}">
<meta property="og:title" content="${TITLE}">
<meta property="og:description" content="${escapeAttribute(locale.description)}">
<meta property="og:image" content="${pageUrl(locale)}og-image.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="${escapeAttribute(locale.imageAlt)}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="${TITLE}">
<meta name="twitter:description" content="${escapeAttribute(locale.description)}">
<meta name="twitter:image" content="${pageUrl(locale)}og-image.png">
${helmet}
<style>[hidden]{display:none !important}${squeezeCss(hoverRules.join(''))}</style>`) +
        `<script type="application/ld+json">${JSON.stringify(structuredData(locale))}</script>`

    let page = `<!DOCTYPE html><html lang="${locale.code}"><head>${head}</head><body>${squeezeHtml(
        body,
    )}<script>${squeezeJs(runtime(locale, component.CLAIM_MS))}</script></body></html>`

    /**
     * Inlined, not linked: an SVG loaded through <img> never fetches the self-hosted
     * fonts. Injected after squeezeHtml, whose <pre> placeholder pass rewrites any
     * " 123 " it finds and would corrupt path data — so squeeze the markup here.
     */
    page = page.replace(/<div class="figure"([^>]*) data-diagram="([\w-]+)"([^>]*)><\/div>/g, (whole, before, name, after) => {
        const file = path.join(repoRoot, `diagrams/${name}${locale.diagramSuffix}.html`)
        const variants = fs.readFileSync(file, 'utf-8').match(/<svg[\s\S]*?<\/svg>/g) ?? []
        if (variants.length === 0) {
            console.error(`build-docs: no <svg> in ${path.relative(repoRoot, file)}`)
            process.exit(1)
        }

        const markup = variants
            .join('')
            .replace(/<!--[\s\S]*?-->/g, '')
            .replace(/>\s+</g, '><')

        return `<div class="figure"${before}${after}>${markup}</div>`
    })

    // Shared from the root rather than copied into each locale.
    if (locale.assetPrefix) {
        page = page
            .replace(/url\(fonts\//g, `url(${locale.assetPrefix}fonts/`)
            .replace(/(src|href)="logo\.svg"/g, `$1="${locale.assetPrefix}logo.svg"`)
    }

    const leftovers = page.match(/\{\{[^}]*\}\}|<sc-(for|if)\b|style-hover=/g)
    if (leftovers) {
        console.error(`build-docs: unresolved template syntax: ${[...new Set(leftovers)].join(', ')}`)
        process.exit(1)
    }

    for (const [, id] of page.matchAll(/data-copy-target="([^"]+)"/g)) {
        if (!page.includes(`id="${id}"`)) {
            console.error(`build-docs: a copy button reads #${id}, which the page does not have`)
            process.exit(1)
        }
    }

    // The page must fetch nothing from anywhere else: a stylesheet, a font or an image
    // from a third party sends every visitor's IP address there before it renders.
    const offSite = page.match(/(?:src|href)="https?:\/\/[^"]*"|url\(\s*['"]?https?:/g) ?? []
    const requests = offSite.filter((reference) => !/^href=/.test(reference))
    if (requests.length > 0) {
        console.error(`build-docs: the page would request ${requests.join(', ')}`)
        process.exit(1)
    }

    return page
}

// llmstxt.org
const llmsTxt = `# ${TITLE}

> ${DESCRIPTION}

Three packages that only work together: a DDEV add-on (the db-test database service
and the ddev playwright commands), a TYPO3 extension (\`plan2net/playwright-toolkit\`,
which clones the databases and hands out a backend session), and an npm package
(\`@plan2net/typo3-playwright-toolkit\`, the Playwright fixtures and content builders).

Version ${version}. GPL-2.0-or-later. TYPO3 CMS 11.5, 12.4, 13.4 and 14.3 on PHP 8.1
to 8.5, with drivers for MariaDB, MySQL, PostgreSQL and SQLite.

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
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
${LOCALES.map(
    (locale) => `  <url><loc>${pageUrl(locale)}</loc>${LOCALES.map(
        (other) => `<xhtml:link rel="alternate" hreflang="${other.code}" href="${pageUrl(other)}"/>`,
    ).join('')}<changefreq>weekly</changefreq><priority>1.0</priority></url>`,
).join('\n')}
</urlset>
`

fs.mkdirSync(outDir, { recursive: true })
const built = []
for (const locale of LOCALES) {
    const directory = path.join(outDir, locale.directory)
    fs.mkdirSync(directory, { recursive: true })
    const page = buildPage(locale)
    fs.writeFileSync(path.join(directory, 'index.html'), page)
    built.push(`${locale.directory ? `${locale.directory}/` : ''}index.html — ${Math.round(page.length / 1024)} KB`)
}

fs.writeFileSync(path.join(outDir, 'llms.txt'), llmsTxt)
fs.writeFileSync(path.join(outDir, 'robots.txt'), robotsTxt)
fs.writeFileSync(path.join(outDir, 'sitemap.xml'), sitemapXml)
fs.copyFileSync(path.join(here, 'logo.svg'), path.join(outDir, 'logo.svg'))
for (const locale of LOCALES) {
    fs.copyFileSync(
        path.join(here, `og-image${locale.directory ? `.${locale.code}` : ''}.png`),
        path.join(outDir, locale.directory, 'og-image.png'),
    )
}
// The licence ships beside them: the OFL asks for it wherever the fonts go.
fs.mkdirSync(path.join(outDir, 'fonts'), { recursive: true })
for (const file of ['caveat.woff2', 'open-sans.woff2', 'source-code-pro.woff2', 'OFL.txt']) {
    fs.copyFileSync(path.join(here, 'fonts', file), path.join(outDir, 'fonts', file))
}

console.log(`site/ — ${built.join(', ')}, plus llms.txt, robots.txt, sitemap.xml`)
