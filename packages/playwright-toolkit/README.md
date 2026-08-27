<p align="center">
  <img src="Resources/Public/Icons/Extension.svg" alt="" width="96" height="96">
</p>
<h1 align="center">plan2net/playwright-toolkit</h1>
<p align="center"><em>One test database per test file, and a ready-made backend session, for TYPO3.</em></p>
<br>

[![Packagist](https://img.shields.io/packagist/v/plan2net/playwright-toolkit)](https://packagist.org/packages/plan2net/playwright-toolkit)
[![TYPO3](https://img.shields.io/badge/TYPO3-11.5%20%7C%2012.4%20%7C%2013.4%20%7C%2014.3-orange)](https://get.typo3.org)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)](https://www.php.net)
[![licence](https://img.shields.io/badge/licence-GPL--2.0--or--later-blue)](LICENSE)

A [TYPO3](https://typo3.org) extension. It creates one test database per test file and provides a ready-made
backend session, so tests never fill in the login form.

Developed in the
[typo3-playwright-toolkit monorepo](https://github.com/plan2net/typo3-playwright-toolkit);
`plan2net/playwright-toolkit` is a read-only mirror that Packagist reads. Open issues and pull
requests on the monorepo — a commit pushed to the mirror is overwritten by the next release.

It needs the [npm package](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/typo3-playwright-toolkit), which runs the tests, and
the [DDEV add-on](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/ddev-typo3-playwright-toolkit), which provides the database
service.

[Requirements](#requirements) · [Install](#install) · [Configure](#configure) ·
[Using the package](#using-the-package) · [Reference](#reference) ·
[Troubleshooting](#troubleshooting) · [Related packages](#related-packages)

## Requirements

- TYPO3 11.5, 12.4, 13.4 or 14.3
- PHP 8.1 or newer
- PostgreSQL, MySQL, MariaDB or SQLite

TYPO3 11.5 and 12.4 are both ELTS. CI verifies each against its last public
release — 11.5.41 and 12.4.45 — because ELTS releases sit behind credentials and
cannot be tested here.

## Install

```bash
composer require --dev plan2net/playwright-toolkit
```

Every part of the extension first checks whether TYPO3 runs in the Testing context,
so it does nothing in Production and Development. Install it as a `--dev`
dependency anyway: that check is not a reason to ship it to production.

## Configure

### Testing host

Give the project a second host name that runs in the Testing context. Your normal
host keeps running in Development, and only the second one serves the test API.

```bash
ddev config --additional-hostnames=example-testing
```

TYPO3 reads the context from an environment variable, so your web server sets it.

For apache-fpm, create `.ddev/apache/context.conf`:

```apache
SetEnvIf Host "." TYPO3_CONTEXT=Development/Docker
SetEnvIf Host "-testing\.ddev\.site$" TYPO3_CONTEXT=Testing
```

For nginx-fpm, edit `.ddev/nginx_full/nginx-site.conf`. Delete the `#ddev-generated`
line at the top first, or DDEV overwrites your changes on the next start:

```nginx
map $http_host $typo3_context {
    default                    "Development/Docker";
    ~*-testing\.ddev\.site$    "Testing";
}

# inside location ~ \.php$
fastcgi_param TYPO3_CONTEXT $typo3_context;
```

Important: a `.ddev/nginx/*.conf` file does not work here. DDEV includes those after
the PHP location block, and nginx then ignores the value.

A complete file is checked in at
[`tests/e2e/consumer/.ddev/nginx_full/nginx-site.conf`](https://github.com/plan2net/typo3-playwright-toolkit/blob/main/tests/e2e/consumer/.ddev/nginx_full/nginx-site.conf)
— copy from there.

Do not write that marker anywhere else in the file, not even in a comment: DDEV
searches the whole file for it. Then run `ddev restart`.

### Database selection

Point TYPO3 at the test database of the current request from
`config/system/additional.php`. TYPO3 auto-loads that file and no context-suffixed
variant, so this is the place:

```php
<?php

if (\TYPO3\CMS\Core\Core\Environment::getContext()->isTesting()) {
    \Plan2net\PlaywrightToolkit\TestContext::applyDatabaseConnectionOverrides();
}
```

Without those lines the overrides never run and every test uses your ordinary
database. Keep the context check: `applyDatabaseConnectionOverrides()` acts on the
test ID alone, so outside the Testing context a request carrying that header would
switch the connection on your ordinary hostname too.

> [!IMPORTANT]
> Under DDEV this file already exists and carries `#ddev-generated`. Delete that line
> first, as with the nginx file, or the next `ddev restart` writes the file again
> without your call — and your tests then pass against your ordinary database.
>
> Put the call at the end of the file. It reads the `Default` connection, which DDEV
> sets in the block above it.

If your project already keeps a separate file per context, put the call in the
Testing one and require that from `additional.php` behind the same check.

> [!NOTE]
> `config/system/additional.php` is the Composer-mode path, which is where TYPO3 12.4,
> 13.4 and 14.3 look. Two older layouts differ, and only the file name changes — the
> contents above are the same:
>
> - **TYPO3 11.5** loads `typo3conf/AdditionalConfiguration.php`, in Composer mode too.
> - **Classic (non-Composer) 12.4 and 13.4** load `typo3conf/system/additional.php`.
>
> `ConfigurationManager::getAdditionalConfigurationFileLocation()` is the authority if
> you need to check for a version not listed here.

It reads your `Default` connection and writes the per-test one back. If a request
carries no test ID, nothing changes and nothing is created: the site uses its normal
database.

Most projects already have this file, holding their own Testing settings. If yours
collects them in an array that is applied afterwards, the line above is overwritten
again. Merge the overrides into that array instead, **last**:

```php
$configurationSettings = array_merge(
    $configurationSettings,
    TestContext::databaseConnectionOverrides($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? [])
);
```

> [!NOTE]
> If that array also carries your **database credentials** — common when they come
> from environment variables rather than `settings.php` — then `$GLOBALS` does not
> name a driver yet at this point, and the call throws
> `The Default database connection names no driver`. Fold the pending values in
> first:
>
> ```php
> $defaultConnection = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? [];
>
> foreach ($configurationSettings as $path => $value) {
>     if (str_starts_with($path, 'DB/Connections/Default/')) {
>         $defaultConnection[substr($path, strlen('DB/Connections/Default/'))] = $value;
>     }
> }
>
> $configurationSettings = array_merge(
>     $configurationSettings,
>     TestContext::databaseConnectionOverrides($defaultConnection)
> );
> ```
>
> `applyDatabaseConnectionOverrides($defaultConnection)` takes the same argument, for
> projects that write to `$GLOBALS` directly.

Important: the returned keys are paths like `DB/Connections/Default/dbname`, not
array keys. If your project writes them with `ArrayUtility::setValueByPath` (or the
same helper it already uses for the other settings), they land correctly. A plain
`array_merge` into `$GLOBALS['TYPO3_CONF_VARS']` creates one key with that literal
name, and your tests then run against your real database.

### Fixtures

Put SQL files in a folder and list them in the extension settings:

```
fixturesPath    = fixtures
fixtureManifest = pages.sql,sys_template.sql
```

The files load in the order you list them, so put parent records first. A fixture is
plain SQL against the schema TYPO3 just built, so `fixtures/pages.sql` can be as
small as a site root and one page under it:

```sql
INSERT INTO pages (uid, pid, doktype, title, slug, is_siteroot) VALUES
    (1, 0, 1, 'Home', '/', 1),
    (2, 1, 1, 'Products', '/products', 0);
```

Your fixtures may set their own uid values, as this one does. You do not have to
reset any sequences afterwards; the extension does that for you, so the first record
a test writes does not collide with uid 2.

Keep the manifest to what every test needs — a site root, a TypoScript template, the
storages your content references. Everything else is faster and clearer built through
the builders in the test that needs it.

## Using the package

Build the template database once, before the tests run:

```bash
ddev playwright-prepare
```

This loads the schema through TYPO3's schema migrator, applies your fixtures, writes
the prepared backend session, and stores a fingerprint of all three. A later run
whose fingerprint still matches skips the rebuild and answers in a moment;
`--force` rebuilds anyway. It also stores the API secret in
`var/playwright/api-secret`. Every test database is a copy of this template.

`ddev playwright` runs this step for you, so you rarely call it directly.

Images are kept apart per test as well. Each test database gets its own folder for
processed images, `fileadmin/_processed_<test id>`, and every conversion gets a scratch
name of its own in `typo3temp/assets/images/`, where TYPO3 works before moving the
result into that folder. Both carry the test ID, so nothing is shared between tests and
both go when the test database does.

To check that a project is set up correctly, ask the health endpoint:

```bash
ddev exec 'curl -sS -H "X-Playwright-Toolkit-Secret: $(cat var/playwright/api-secret)" \
    -H "X-Playwright-Test-Id: HEALTHCHECK00001" \
    https://example-testing.ddev.site/typo3/test-api/health'
```

It answers `{"ok":true,…}` and names the test database it just created from your
template. Send the test ID: without it the request uses the project's own database,
and the check fails with a 503 saying so. A 404 means the request never reached the
Testing context, a 401 means the secret does not match.

## Reference

### Extension settings

| Name | Default | Purpose |
|---|---|---|
| `fixturesPath` | — | Folder with your SQL fixture files, relative to the project root |
| `fixtureManifest` | — | Fixture file names, separated by commas, loaded in this order |
| `preseededSessionId` | `playwright_test_session` | Backend session ID stored in the template database |
| `sessionUserId` | `1` | Backend user this session belongs to |
| `cleanupMinimumAgeMs` | `3600000` | How old a test database must be before cleanup may delete it |

If you change `fixturesPath`, `fixtureManifest` or the session settings, the next run
rebuilds the template database.

### Endpoints

All endpoints start with `/typo3/test-api/`, need the
`X-Playwright-Toolkit-Secret` header, and answer `404` outside the Testing context.
[CONTRACT.md](https://github.com/plan2net/typo3-playwright-toolkit/blob/main/CONTRACT.md) describes them.

The one exception is `inspect`, which a browser opens. It takes a signed link
instead of the header, because a browser cannot send one. See Looking at a kept
database below.

### Looking at a kept database

When a test fails its database is kept, and the test run prints a link for it:

```
tests/checkout.spec.ts → dbABCD1234EFGH5678
  https://example-testing.ddev.site/typo3/test-api/inspect?id=…&t=…
```

Open it and you are in the TYPO3 backend of that test's database, logged in, with
the frontend reachable from there. You need no browser extension and no header.

The backend shows the scenario and its test ID next to the site name in the top left
corner, so two open tabs are never confused:

```
PlaywrightDemo [checkout · EF70E3DDD33D3571]
```

The scenario is the spec file's name, without its directory or `.spec.ts` suffix.
Nothing to configure; the marker appears whenever a request carries a test ID.

The link is signed with the API secret and lives **15 minutes**. It sets two
session cookies, so closing the browser ends the visit.

### One database holding every scenario

`ddev playwright-replay` runs every scenario's setup into a single database instead
of one per test file, so everything the suite knows how to build ends up in one
place. It calls `typo3 playwright:replay-prepare` first, which rebuilds that database
from the template.

The database is the plain `db` on the `db-test` container, reached through the fixed
test ID `REPLAY0000000000`. Each scenario writes into a sysfolder named after its
file, and the run prints a backend link when it finishes. Sample pages nobody had to
build by hand.

## Troubleshooting

**Every endpoint answers 404.** The request did not reach the Testing context. Open
the testing host name, not the normal one, and check your web server configuration.

**Tests write to your normal database.** Your project merges the override paths as
array keys, or DDEV rewrote the file because its `#ddev-generated` marker is still
there. See Database selection.

**The run stops with "run ddev playwright-prepare".** The template database is
missing or was built with different settings. Run `ddev playwright-prepare` again.

**Every endpoint answers 401.** The npm package and PHP do not share the secret
file. If they run in different containers, set `PLAYWRIGHT_TOOLKIT_SECRET` to the
same value on both sides.

## Related packages

- [`@plan2net/typo3-playwright-toolkit`](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/typo3-playwright-toolkit) — npm package
- [DDEV add-on](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/ddev-typo3-playwright-toolkit) — database service and commands
