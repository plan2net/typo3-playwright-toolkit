# Setting up the TYPO3 Playwright Toolkit

Six steps, in order. At the end you have a passing test. For what the toolkit is and
why, read the [README](README.md) first.

This guide takes the DDEV route, which is the shortest one and the one CI proves on
every push. DDEV is not a requirement: see
[Without DDEV](README.md#without-ddev) for what to provide instead.

## 1. A second hostname, in the Testing context

The toolkit does not install a second site. It uses **your** site, reached through a
second hostname. On that hostname TYPO3 runs in the Testing context, and there every
test gets its own database. Your normal hostname stays in Development and keeps its
own database.

Your web server decides the context, so you set this part up yourself.

```bash
ddev config --additional-hostnames=example-testing
```

TYPO3 reads the context from an environment variable, which the web server sets.

For **apache-fpm**, create `.ddev/apache/context.conf`:

```apache
SetEnvIf Host "." TYPO3_CONTEXT=Development/Docker
SetEnvIf Host "-testing\.ddev\.site$" TYPO3_CONTEXT=Testing
```

For **nginx-fpm**, edit `.ddev/nginx_full/nginx-site.conf` and delete the
`#ddev-generated` line at the top first, or DDEV overwrites your changes:

```nginx
map $http_host $typo3_context {
    default                    "Development/Docker";
    ~*-testing\.ddev\.site$    "Testing";
}

# inside location ~ \.php$
fastcgi_param TYPO3_CONTEXT $typo3_context;
```

A `.ddev/nginx/*.conf` file does not work: DDEV includes those after the PHP location
block, and nginx ignores the value. A complete file is checked in at
[`tests/e2e/consumer/.ddev/nginx_full/nginx-site.conf`](tests/e2e/consumer/.ddev/nginx_full/nginx-site.conf).

A `TYPO3_CONTEXT` in your `web_environment` can stay. The web server's value wins per
request, and yours still applies to every other hostname.

`ddev restart`, then open the new hostname. A `500` with `#1396795884` means the name is
missing from `SYS/trustedHostsPattern`. Log in, and the backend's top bar says Testing.

Step 6 checks this too, and stops if the site answers in another context.

## 2. The three packages

```bash
ddev add-on get https://github.com/plan2net/typo3-playwright-toolkit/releases/latest/download/ddev-typo3-playwright-toolkit.tar.gz
ddev restart
ddev composer require --dev plan2net/playwright-toolkit

mkdir -p tests/playwright && cd tests/playwright
ddev npm init -y && ddev npm pkg set type=module
ddev npm i -D @plan2net/typo3-playwright-toolkit @playwright/test
```

Do not skip the `npm init`. Without a `package.json` of its own, `npm i` walks up the
directories and installs into the project above. `type: module` is needed because the
Playwright config in step 4 uses `import.meta.url`.

If your project already runs Playwright, install `@plan2net/typo3-playwright-toolkit`
alone and keep the `@playwright/test` you have: 1.44 and newer work. Then read
[Migrating an existing Playwright suite](#migrating-an-existing-playwright-suite) —
your configuration mostly carries over, your tests do not.

The add-on is the optional one. It gives you the `db-test` database service and the
`ddev playwright*` commands; without it you point four environment variables at a
database server of your own and run the underlying commands yourself, as
[Without DDEV](README.md#without-ddev) describes. The other two packages are
required.

Keep all three packages on the same version. `tests/playwright` is only the default
directory; `PW_TEST_DIR` in `web_environment` moves it. If your project already uses
`Lullabot/ddev-playwright`, remove it first with `ddev add-on remove ddev-playwright`:
it has a `ddev playwright` command too, and only one of them can win. That deletes every
file it installed, and most of them are checked into your repository, so commit what you
have before you run it.

## 3. The browsers

Choose where they go **before** you install them. Otherwise they land in the
container's home directory, which DDEV drops on the next rebuild, and you download
them again:

```yaml
# .ddev/config.yaml
web_environment:
    - PLAYWRIGHT_BROWSERS_PATH=/var/www/html/.cache/ms-playwright
```

```bash
ddev restart
cd tests/playwright && ddev npx playwright install --with-deps chromium
```

## 4. Five files of your own

`tests/playwright/tsconfig.json`, so your editor and `tsc` see the package's types:

```json
{
    "extends": "@plan2net/typo3-playwright-toolkit/tsconfig.base.json",
    "include": ["**/*.ts"]
}
```

`config/system/additional.php`:

```php
<?php

$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['playwright_toolkit'] = [
    'fixturesPath' => 'tests/playwright/fixtures',
    'fixtureManifest' => '010-root-page.sql',
];

if (\TYPO3\CMS\Core\Core\Environment::getContext()->isTesting()) {
    \Plan2net\PlaywrightToolkit\TestContext::configureCurrentRequest();
}
```

Keep the context check. Without it, any request that sends a test-ID header could
switch the database on your normal hostname too.

> [!IMPORTANT]
> Two rules about where that call goes. Break either one and your tests run against
> your real database and still pass.
>
> - Put it **after** everything else in the file that writes `DB/Connections/Default`.
>   Many projects collect their settings in an array and apply it at the end; those
>   need the form shown in
>   [Database selection](packages/playwright-toolkit#database-selection).
> - Code in this file that **reads** the database runs before the switch, so it reads
>   the normal database. A `be_users` lookup that fills `SYS/systemMaintainers` is the
>   common case. Skip it in the Testing context.

That path is the Composer one for TYPO3 12.4 and up. TYPO3 11.5 reads
`typo3conf/AdditionalConfiguration.php`, and classic-mode installations read
`typo3conf/system/additional.php`. Only the name changes.

`tests/playwright/fixtures/010-root-page.sql`. The template database holds your
schema and a backend session, and no content at all. Without a root page your site
resolves nothing and every test gets a 404:

```sql
INSERT INTO pages (uid, pid, title, slug, doktype, is_siteroot, hidden, deleted)
VALUES (1, 0, 'Root', '/', 1, 1, 0, 0);
```

The `uid` has to be the `rootPageId` of your site configuration, and that
configuration has to exist — it is a file, so the template already has it.

Write the SQL by hand, against the current schema. A dump of your project database
does not work: it still has columns that TYPO3 removed in an older upgrade, and the
template does not have them.

Your site also needs TypoScript with a `PAGE` object, or the frontend stays empty.
TYPO3 13 and up read `config/sites/<site>/setup.typoscript`, another file the
template gets for free. Older versions need a `sys_template` row in the fixture too.

`tests/playwright/playwright.config.ts`:

```ts
import { fileURLToPath } from 'url'
import { defineToolkitConfig, defineBasePlaywrightConfig } from '@plan2net/typo3-playwright-toolkit/playwright'

const toolkit = defineToolkitConfig({
    testingURL: 'https://example-testing.ddev.site',
    paths: { consumerRoot: fileURLToPath(new URL('../..', import.meta.url)) },
})

export default defineBasePlaywrightConfig(toolkit, { testDir: './tests' })
```

`testingURL` is the hostname from step 1, and the only URL you have to give. The
[npm README](packages/typo3-playwright-toolkit#configure) lists every other option.

And `tests/playwright/.gitignore`, for what a run leaves behind:

```gitignore
node_modules/
test-results/
playwright-report/
```

## 5. Your first test

`tests/playwright/tests/first.spec.ts`. The setup builds the content, the test reads
it back:

```ts
import { defineScenario, expect } from '@plan2net/typo3-playwright-toolkit'

const HEADER = 'Hello from the toolkit'

const test = defineScenario(async ({ builders }) => {
    const page = await builders.page().withTitle('First').withSlug('/first').atParentId(1).create()

    await builders
        .content()
        .onPage(page.id)
        .ofType('header')
        .configure((content) => content.withHeader(HEADER))
        .create()

    return { slug: page.slug }
})

test('renders what the builders wrote', async ({ page, state }) => {
    await page.goto(state.slug)

    await expect(page.getByText(HEADER)).toBeVisible()
})
```

## 6. Run it

```bash
ddev playwright test
```

The first run builds the template database, so it takes longer than the ones after
it. [Writing a test](README.md#writing-a-test) explains what the pieces do.

## Migrating an existing Playwright suite

The six steps assume an empty directory. A project that already runs Playwright keeps
most of its configuration and none of its tests.

Do not run the old tests and the new ones together. A file that does not come from
`defineScenario` sends no test ID, and a request without one gets the site's ordinary
database. Nothing fails: the test passes, against content the toolkit never built. Give
the toolkit a `testDir` of its own, and move a test into it once you have rewritten it as
a scenario.

Your configuration mostly carries over. The second argument is an ordinary Playwright
config, and what you write there wins over the toolkit's defaults, so `testDir`,
`reporter`, `projects`, `retries`, `timeout` and the rest stay as they are:

```ts
export default defineBasePlaywrightConfig(toolkit, {
    testDir: './tests',
    reporter: [['list'], ['junit', { outputFile: 'junit.xml' }]],
    expect: { toHaveScreenshot: { maxDiffPixelRatio: 0.01 } },
    use: { ignoreHTTPSErrors: true, locale: 'de-DE' },
    projects: [{ name: 'Desktop Chrome', use: { ...devices['Desktop Chrome'] } }],
})
```

`use` and `expect` are merged key by key instead of replaced, so a locale of your own
keeps the tracing and the screenshot tolerances the toolkit sets.

It refuses four keys. `globalSetup` and `globalTeardown` carry the preflight, the run
bookkeeping and the cleanup that drops every database the run created. `use.baseURL` and
`use.serviceWorkers` keep the test ID on one origin, and a project's own `use` is
restricted the same way. TypeScript rejects all four, and a JavaScript config gets an
error naming the key and the reason.

Keep the `@playwright/test` you have, 1.44 or newer. Install
`@plan2net/typo3-playwright-toolkit` on its own and your version stays.

Your tests can stay where they are. The `ddev playwright*` commands look in
`tests/playwright`; if yours live somewhere else, name that directory in `PW_TEST_DIR`.
The commands run inside the web container and never see a variable you export in your
shell, so it belongs in `.ddev/config.yaml`:

```yaml
web_environment:
    - PW_TEST_DIR=test/playwright
```

Step 2 sets `type: module` in the `package.json` next to your tests, because the config
in step 4 uses `import.meta.url`. If your existing tests cannot move to ESM, leave that
`package.json` alone and name the config `playwright.config.mts` instead.

Screenshot references are stored per test file and test title, so a rewritten test starts
without one and Playwright writes it on the first run. Look at that first picture before
you keep it: it matches the old one only if your scenario builds the same content.
