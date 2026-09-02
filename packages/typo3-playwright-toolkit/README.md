<p align="center">
  <img src="https://raw.githubusercontent.com/plan2net/typo3-playwright-toolkit/main/packages/playwright-toolkit/Resources/Public/Icons/Extension.svg" alt="" width="96" height="96">
</p>
<h1 align="center">@plan2net/typo3-playwright-toolkit</h1>
<p align="center"><em>Playwright fixtures and content builders for TYPO3, one test database per test file.</em></p>
<br>

[![npm](https://img.shields.io/npm/v/@plan2net/typo3-playwright-toolkit)](https://www.npmjs.com/package/@plan2net/typo3-playwright-toolkit)
[![Node](https://img.shields.io/badge/Node-22.12%2B-5fa04e)](https://nodejs.org)
[![Playwright](https://img.shields.io/badge/Playwright-1.44%2B-2ead33)](https://playwright.dev)
[![licence](https://img.shields.io/badge/licence-GPL--2.0--or--later-blue)](LICENSE)

An npm package. It provides the [Playwright](https://playwright.dev) fixtures, the
content builders that create [TYPO3](https://typo3.org) records, and the
accessibility ([axe](https://github.com/dequelabs/axe-core)) and CSP checks.

It needs the [Composer extension](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/playwright-toolkit), which creates the test
databases, and the [DDEV add-on](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/ddev-typo3-playwright-toolkit), which provides
the database service and the commands.

> [!IMPORTANT]
> Setting this up for the first time? Follow
> **[SETUP.md](https://github.com/plan2net/typo3-playwright-toolkit/blob/main/SETUP.md)**
> instead. It covers all three packages in order. This README documents the npm
> package on its own.

[Requirements](#requirements) · [Install](#install) · [Configure](#configure) ·
[Using the package](#using-the-package) · [Reference](#reference) ·
[Troubleshooting](#troubleshooting) · [Related packages](#related-packages)

## Requirements

- Node 22.12 or newer
- `@playwright/test` 1.44 or newer, so a project that already runs Playwright keeps its
  version and its screenshot baselines
- The Composer extension, installed in the same project

You do not need a database client. This package never talks to the database; it asks
the extension to create and delete them. That is why PHP and Node may run in
different containers.
[Where things run](https://github.com/plan2net/typo3-playwright-toolkit#where-things-run)
shows how.

## Install

```bash
npm i -D @plan2net/typo3-playwright-toolkit @playwright/test
```

Add a `tsconfig.json` next to your tests that extends the one shipped here:

```json
{
    "extends": "@plan2net/typo3-playwright-toolkit/tsconfig.base.json",
    "include": ["**/*.ts"]
}
```

> [!IMPORTANT]
> This package is ESM and needs `"moduleResolution": "NodeNext"`. Without it
> TypeScript cannot read the package's exports, so `page` and `state` in your tests
> type as `any` and you lose every check your editor could give you.

## Configure

Call `defineToolkitConfig(...)` at the top of your `playwright.config.ts`, before any
other file from this package is loaded. Two values are enough:

```ts
// <project>/tests/playwright/playwright.config.ts
import { fileURLToPath } from 'url'
import { defineToolkitConfig, defineBasePlaywrightConfig } from '@plan2net/typo3-playwright-toolkit/playwright'

const toolkitConfig = defineToolkitConfig({
    testingURL: 'https://example-testing.test',
    paths: { consumerRoot: fileURLToPath(new URL('../..', import.meta.url)) },
})

export default defineBasePlaywrightConfig(toolkitConfig, {
    testDir: './tests',
})
```

`testingURL` is the host name that runs in the Testing context. It is the only URL
this package uses, because the test database exists nowhere else.

`consumerRoot` is the root of your TYPO3 project. The package derives the state and
session folders from it, so it works from `node_modules` without knowing its own
location. Count the `..` from where your config actually sits — the example assumes
`<project>/tests/playwright/`, so it climbs two levels. Pointing it at the wrong
directory is not an error: the run works and writes its state somewhere you did not
expect. Use `fileURLToPath` rather than `new URL(...).pathname`, which
percent-encodes a project path containing spaces or non-ASCII characters.

> [!IMPORTANT]
> `defineToolkitConfig` must run before the first import from this package.
> Everything else reads the values it stores.

## Using the package

### Writing a test

One test file is one scenario: a setup function that creates content through the real
TYPO3 backend, and the tests that read it.

```ts
// tests/my-feature.spec.ts
import { defineScenario, expect, flexForm } from '@plan2net/typo3-playwright-toolkit'

const test = defineScenario(async ({ builders }) => {
    const { id, slug } = await builders
        .page()
        .withTitle('My Page')
        .withSlug('/my-page')
        .create()

    await builders
        .content()
        .onPage(id)
        .ofType('textmedia')
        .configure((element) => element.withHeader('Hello').withBodyText('<p>Copy.</p>'))
        .create()

    await builders
        .content()
        .onPage(id)
        // your own CType, registered as shown under "Your own content types"
        .ofType('article')
        .configure((element) =>
            element.withField('pi_flexform', flexForm({ 'settings.limit': 3 })),
        )
        .create()

    return { slug }
})

test('renders the page title', async ({ page, state }) => {
    await page.goto(state.slug)

    await expect(page.locator('h1')).toHaveText('My Page')
})
```

`state` is whatever your setup returned. The first test that needs it runs the
setup, and the other tests wait. If the setup fails, the tests are skipped with the
reason instead of failing because content is missing.

The `slug` you get back is the one the site stored, which is not always the one you
asked for — a translation and a name already in use are two cases, and an extension
can add more. Navigate with the returned value, never with the string you passed in.

The setup runs once per file, even when the file runs in several browser projects.
The other projects use the state and the test database that the first one created.

### When something fails, TYPO3 says why

A failing test now prints what TYPO3 wrote to its log while that test ran, under the
failure itself:

```
    Error: expect(locator).toBeVisible() failed
    Locator: locator('.teaser figure img')

    Error: [typo3-playwright-toolkit] TYPO3 recorded 2 errors for scenario
    "tests/teaser.spec.ts" (test 2C8F069F788D3F20):

      1. log error
         No page configured for type=99999.

      2. DataHandler sys_file_reference
         Attempt to insert a record on page '/gallery' (127) where this table is not allowed
```

The same list is attached to the test as `typo3-errors.json`. A message that repeats
is counted, not printed again. A failing setup shows the same list next to its own
error.

If TYPO3 refuses a record while a builder saves it, the builder stops at that line.
Before, the save looked fine and the test failed later, somewhere unrelated.

<picture>
  <source media="(max-width: 700px)" srcset="https://raw.githubusercontent.com/plan2net/typo3-playwright-toolkit/main/diagrams/scenario-fan-out-narrow.svg">
  <img width="880" src="https://raw.githubusercontent.com/plan2net/typo3-playwright-toolkit/main/diagrams/scenario-fan-out.svg"
       alt="One test file runs its setup once. If the setup succeeds it produces state and a test database of its own, which every browser project then reuses. If the setup fails, the tests of that file are skipped with the reason rather than failing individually.">
</picture>

Besides `builders`, the setup receives `testId` (the database this attempt runs
against), `attempt` (`1` on the first try), `signal` (aborted when the attempt times
out, so pass it to your own long requests), and a `page` and `request` that already
carry the toolkit headers. Your tests get `testId` beside `state`, which is the
database name without its `db` prefix and what the backend shows in brackets behind
the site name:

```ts
test('reports which database it used', async ({ page, state, testId }) => {
    await page.goto(state.slug)

    console.log(`built in db${testId}`)
})
```

### Your own content types

Every CType of a normal TYPO3 installation already has a builder. For your own,
extend `CoreContent` to get the shared setters, then register the class:

```ts
import { CoreContent } from '@plan2net/typo3-playwright-toolkit'

export class ArticleContent extends CoreContent {
    readonly type = 'article'

    withTeaser(text: string): this {
        return this.withField('tx_myext_teaser', text)
    }
}
```

```ts
// playwright.config.ts
contentTypes: { article: ArticleContent }
```

To get typed setters in `.configure()`, add the class to `ContentTypeMap`:

```ts
declare module '@plan2net/typo3-playwright-toolkit' {
    interface ContentTypeMap {
        article: ArticleContent
    }
}
```

A key with the name of a core CType replaces the built-in builder, and the other
core types stay as they are. That works for the type too: `ContentTypeMap` starts
empty, so your `text: MyTextContent` is what `.configure()` hands you.

### Files and child records

Four setters attach a relation, and they work the same on a content type, on a
child record, and on the builder in a test:

```ts
export class AccordionContent extends CoreContent {
    readonly type = 'accordion'

    withItems(items: AccordionItem[]): this {
        return this.withChildren('items', 'tx_myext_accordion_item', items, (item, data) =>
            item
                .withField('title', data.title)
                .withFileReference('image', data.imageId),
        )
    }
}
```

`withFileReference(column, fileUid)` and `withFileReferences(column, fileUids)` take
the reference's own fields as a third argument, such as a `crop`. `withChild` and
`withChildren` hand the callback a record with the same four setters, so a child can
carry its own files and children.

The order you call them in is the order the records get. A child takes `pid` and
`sys_language_uid` from the record above it, so a translation sets the language once,
at the top.

Never set `uid_local`, `uid_foreign`, `tablenames`, `fieldname` or `sorting_foreign`:
the toolkit writes them, and your own value fails. A wrong `uid_foreign` saves a row
that points at nothing, and TYPO3 reports no error.

If a builder has no setter for a column, add the relation in the test:

```ts
await builders.content().onPage(page.id).ofType('textmedia')
    .configure((element) => element.withHeader('Gallery', 'h2'))
    .withFileReferences('assets', [1, 2], { crop: imageCrop({ ratio: '16:9' }) })
    .create()
```

Fill a column from one place only. Both the content type and the test, or `withField`
on top, fails.

A setter that changes the reference, such as a crop, may run after the one that
attaches the file. Write the reference at the end then, and hand the rest to `super`:

```ts
import type { RelationOwner, RelationOutput } from '@plan2net/typo3-playwright-toolkit'

override getRelations(owner: RelationOwner): RelationOutput {
    this.withFileReference('image', this.fileUid, { crop: imageCrop({ ratio: this.ratio }) })

    return super.getRelations(owner)
}
```

### One request for several elements

Every `create()` is a POST, and every POST boots the backend, builds the form and runs
DataHandler. A page with six elements pays for that six times, which is most of what
its setup costs. `builders.batch()` puts them in one:

```ts
const [hero, intro, gallery] = await builders.batch(
    builders.content().onPage(page.id).ofType('header')
        .configure((element) => element.withHeader('Hero')),
    builders.content().onPage(page.id).ofType('text')
        .configure((element) => element.withBodytext('<p>Intro</p>')),
    builders.content().onPage(page.id).ofType('textmedia')
        .configure((element) => element.withHeader('Gallery'))
        .withFileReferences('assets', [1, 2]),
)
```

The queued builders get no `.create()` — `batch` saves them. They land on the page in
the order you list them, you get a uid for each, and their files and child records come
along in the same request.

All the elements have to belong to one page, because a request positions them after one
another and that means nothing across pages. They also cannot point at each other: a
relation needs a uid, and only a save hands one back. Create what is pointed at first,
then batch the rest:

```ts
const container = await builders.content().onPage(page.id).ofType('my_container').create()

await builders.batch(
    builders.content().onPage(page.id).ofType('text')
        .configure((element) => element.inContainer(Number(container.id))),
    builders.content().onPage(page.id).ofType('header')
        .configure((element) => element.inContainer(Number(container.id))),
)
```

A content type whose children hang off a column of its own needs none of this:
`withChildren` already writes them in the same request as their parent. Batching is for
elements that sit side by side on a page.

### Calling the site directly

The `request` client — the one `defineScenario` hands your setup, and the `request`
fixture in your tests — carries the test ID for `testingURL`, so it reads and
writes the same throwaway database the browser does. Requests to any other host
get neither toolkit header.

### Stubbing a third-party script

A cookie banner or a tracking script can swallow the clicks your test makes.
`prepareContext` runs on every context a test uses, after the toolkit's own
routes are in place:

```ts
// playwright.config.ts
prepareContext: async (context) => {
    await context.route('**/vendor-widget.js', (route) => route.fulfill({ body: '' }))
},
```

### Screenshots

`expectScreenshot` waits for fonts, images and animations, hides the selectors from
`hideBeforeScreenshot`, and then compares against the stored image.

```ts
import { expectScreenshot } from '@plan2net/typo3-playwright-toolkit'

await expectScreenshot(page, 'my-page')                             // the whole page
await expectScreenshot(page, 'accordion', { include: '.accordion' }) // one element
```

The name carries no file extension; Playwright adds `.png` and the platform suffix
itself. The first run writes the missing image and fails, as Playwright always does.
Every other option is passed on to `toHaveScreenshot`, and the tolerances come from
`screenshot` in `defineToolkitConfig`.

> [!IMPORTANT]
> Images are stored in CSS pixels, which is Playwright's default. If your projects
> set a `deviceScaleFactor`, a bug that only shows at 2x cannot fail a test, and
> images taken with `element.screenshot()` will not match. Ask for device pixels per
> shot, or for the whole project:
>
> ```ts
> await expectScreenshot(page, 'hero', { scale: 'device' })
>
> defineBasePlaywrightConfig(toolkit, {
>     expect: { toHaveScreenshot: { scale: 'device' } },
> })
> ```

`expectScreenshot` waits for animations itself. When you interact and then assert
without a screenshot — an accessibility scan after opening an accordion, say — wait
first:

```ts
import { waitForAnimations } from '@plan2net/typo3-playwright-toolkit'

await page.locator('summary').first().click()
await waitForAnimations(page, '.accordion')
await runAccessibilityScan(page, { include: '.accordion' })
```

### Accessibility checks

`runAccessibilityScan` checks the current page with axe and fails the test if it
finds a problem.

```ts
import { runAccessibilityScan } from '@plan2net/typo3-playwright-toolkit'

await runAccessibilityScan(page)                       // the whole page
await runAccessibilityScan(page, { include: '.card' }) // one component
await runAccessibilityScan(page, { exclude: '#ads' })
await runAccessibilityScan(page, { disabledRules: ['color-contrast'] })
```

> [!IMPORTANT]
> The test also fails when the check ran no rules at all. An empty area would
> otherwise pass without testing anything.

A check with `include` turns off the `heading-order` rule, because axe compares the
headings of a part of the page with the structure of the whole page. Checks of a
whole page do test the heading structure.

To check every test without writing the call each time, turn it on in the config:

```ts
accessibility: { auto: true },
```

Every test that opened a page is then scanned after it finishes. A test that never
navigated is skipped, because there is nothing to check, and a test that already
failed is skipped too, so the first failure stays the one you read.

`scanAccessibility(page, options)` returns the raw axe result instead of failing the
test. It returns `null` when the current project does not run checks.

### CSP violations

`CspVerifier` collects policy violations from your own pages and fails the test if
there were any.

```ts
import { CspVerifier } from '@plan2net/typo3-playwright-toolkit'

const verifier = new CspVerifier(context)
await verifier.install()          // before the first page is opened

await page.goto('/')

await verifier.assertNoViolations(testInfo)  // testInfo is optional
```

> [!IMPORTANT]
> `install()` must run before anything is loaded. A page that is already open
> reports nothing.

The test also fails if pages were requested but none of your own pages ever arrived,
so a navigation that never loaded cannot pass. Pass `testInfo` to attach the full
report as a JSON file when the test fails.

## Reference

### Configuration options

Required:

| Name | Purpose |
|---|---|
| `paths.consumerRoot` | Absolute path to your TYPO3 project root |
| `testingURL` | Bare origin that runs in the Testing context |

Everything else:

| Name | Default | Purpose |
|---|---|---|
| `accessibility.auto` | `false` | Scan after every test that opened a page, instead of calling `runAccessibilityScan` yourself |
| `accessibility.disabledRules` | `[]` | axe rules turned off for the whole project |
| `accessibility.projects` | all projects | Projects that run axe checks |
| `accessibility.tags` | `DEFAULT_SCAN_TAGS` | Which axe rule sets to run |
| `cleanup.failOnLeak` | true in CI | Fail the run if a test database could not be deleted |
| `cleanup.orphanAgeMs` | 24 hours | How old a leftover database must be before cleanup deletes it |
| `cleanup.preserveOnFailure` | `failed` | Which test databases to keep after a failure: `failed`, `all` or `none` |
| `contentTypes` | `{}` | Your own content builders, one per CType |
| `csp.expectedOrigin` | origin of `testingURL` | Whose violations count |
| `csp.mode` | `any` | Which policy header a page must send: `any`, `report-only` or `enforced` |
| `hideBeforeScreenshot` | `[]` | CSS selectors hidden before every screenshot |
| `paths.sessionDir` | `<consumerRoot>/var/session` | TYPO3 session folder, cleaned after a run |
| `paths.stateDir` | `<consumerRoot>/.test-state` | Folder for setup state |
| `prepareContext` | none | Runs on every context a test uses, for your own routes and stubs |
| `screenshot.maxDiffPixelRatio` | `0.005` | How many pixels may differ, as a share of the image |
| `screenshot.threshold` | `0.2` | How different one pixel may be, from 0 to 1 |
| `setup.attemptTimeoutMs` | `90000` | How long one setup attempt may take |
| `setup.attempts` | `2` | Attempts per setup, so `2` means one retry |
| `setup.lockStaleMs` | `15000` | Silence after which another worker may take over the setup |
| `setup.pollMs` | `100` | Gap between polls while waiting for a scenario |
| `setup.waitTimeoutMs` | `300000` | How long a test waits for its scenario in total, lock included |

`defineBasePlaywrightConfig` sets Playwright's `baseURL` to `testingURL` and adds
this package's setup and cleanup functions. Values in its second argument win, and
`use` and `expect` are merged instead of replaced. Four keys cannot be overridden
and are refused with an error: `globalSetup` and `globalTeardown` carry the
preflight and the database cleanup, `use.baseURL` must stay the testing origin, and
`use.serviceWorkers` stays `block` because a service worker serves past the routing
that carries the test ID. Add your own setup as a Playwright project dependency
instead.

### Content types

Every CType of a normal TYPO3 installation has a builder, and you register nothing:
`header`, `text`, `textmedia`, `textpic`, `image`, `bullets`, `table`, `uploads`,
`html`, `div`, `shortcut`, and the eleven `menu_*` types.

All builders share `withHeader`, `withSubheader`, `withHeaderLayout`,
`withHeaderLink`, `withColPos`, `setHidden`, and `withField(column, value)` for any
other TCA column. They also share the four relation setters above —
`withFileReference`, `withFileReferences`, `withChild` and `withChildren`. Types with
images add `withFile` and `withFiles` for their own media column, plus `withColumns`,
`withOrientation` and `withImageSize`.

Where core stores a number that means nothing on its own, the setter takes the name
and your editor suggests the options:

```ts
element.withHeader('Chapter', 'h3')      // header_layout 3, or 'hidden' for 100
element.withBulletsType('numbers')       // bullets_type 1
element.withHeaderPosition('top')        // table_header_position 1
element.withOrientation('in-text-right') // imageorient 17
```

`PageBuilder` does the same for the page type: `withDoktype('folder')` writes 254.
It also takes a number, for a doktype your own project registered.

The `crop` column of a file reference holds JSON text, which `imageCrop()` writes for
you. Without an area it keeps the whole image, which is what naming only a ratio
means:

```ts
imageCrop({ ratio: '16:9' })
imageCrop({ area: { x: 0.1, y: 0, width: 0.5, height: 1 } })
```

Where the column declares `cropVariants`, name each one with `imageCrops()`:

```ts
imageCrops({ mobile: { ratio: '9:16' }, desktop: { ratio: '16:9' } })
```

A plugin's settings go through `withSetting`, one call per setting:

```ts
element.withSetting('limit', 10).withSetting('order', 'title')
```

Each one becomes a `settings.` entry and they are written to `pi_flexform` together,
so two calls never overwrite each other. That is what a builder for your own plugin
uses:

```ts
export class EventListContent extends CoreContent {
    readonly type = 'myext_eventlist'

    upcomingOnly(): this {
        return this.withSetting('onlyUpcoming', '1')
    }
}
```

For a structure with named sheets, or a key outside `settings.`, write the column
yourself with `flexForm()` — the form posts one field per value, not one for the
column:

```ts
element.withField('pi_flexform', flexForm({ sDEF: {…}, sFilter: {…} }))
```

### The inspect command

The package installs `typo3-playwright-inspect`. Run it from your project root
after a failed run to print a backend link for every test database that was kept,
optionally filtered by a part of the test file name:

```bash
npx typo3-playwright-inspect            # every kept database
npx typo3-playwright-inspect accordion  # only matching test files
```

It reads the API secret from `var/playwright/api-secret` or from
`PLAYWRIGHT_TOOLKIT_SECRET`. On DDEV, `ddev playwright-inspect` wraps it. The links
log in as the pre-seeded backend user and live 15 minutes.

`--replay` prints a link into the database a replay run built, instead of the kept
test databases. Use it when the link that run printed has expired — it mints a new
one rather than rebuilding anything.

### Replay mode

`PW_REPLAY=1` runs every scenario's setup into one shared database rather than a
per-test one, so all the content the suite builds ends up in a single place you can
browse and export. `ddev playwright-replay` sets it, rebuilds that database from the
template first, and prints a backend link when the run ends.

What changes while it is set: every scenario uses the one fixed test ID
`REPLAY0000000000`, its content goes into a sysfolder named after it under the
fixture root, slugs keep no test-ID suffix, setups run once with no retry, the tests
themselves are skipped, and teardown drops nothing.

## Troubleshooting

**"No test ID for this request".** The builder received a page that the toolkit
fixtures did not create. Use the `builders` argument of `defineScenario`.

**Settings seem to be ignored.** `defineToolkitConfig` ran too late. It must be the
first thing in `playwright.config.ts`.

**Cleanup refuses a path.** `stateDir` and `sessionDir` must be absolute and inside
`consumerRoot`, because cleanup deletes files in both.

**"Timed out after 300000ms waiting for the setup".** A setup that builds a lot of
content can outgrow the defaults on a slow machine. Raise `setup.attemptTimeoutMs`
for the setup itself and `setup.waitTimeoutMs` for the tests waiting on it — the
second has to stay comfortably above the first, since it covers every attempt plus
the time spent waiting for the lock.

**Test databases stay after a failed run.** That is intended: the databases of the
failed test files are kept for debugging, and the run prints a link for each one
that opens it in the TYPO3 backend. Set `cleanup.preserveOnFailure` to `none` to
turn this off.

**You want to look at what a failing test built.** Open the link the run printed
under "Kept for debugging". It logs you into that test's backend, and the frontend
is reachable from there. The link is signed with the API secret and lives 15
minutes.

## Related packages

- [`plan2net/playwright-toolkit`](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/playwright-toolkit) — Composer extension
- [DDEV add-on](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/ddev-typo3-playwright-toolkit) — database service and commands
