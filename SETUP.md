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

`ddev restart`, then open the new hostname and check the backend reports Testing.

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

The add-on is the optional one. It gives you the `db-test` database service and the
`ddev playwright*` commands; without it you point four environment variables at a
database server of your own and run the underlying commands yourself, as
[Without DDEV](README.md#without-ddev) describes. The other two packages are
required.

Keep all three packages on the same version. `tests/playwright` is only the default
directory; `PW_TEST_DIR` in `web_environment` moves it. If your project already uses
`Lullabot/ddev-playwright`, remove it first: it has a `ddev playwright` command too,
and only one of them can win.

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
