# plan2net/playwright-toolkit

[![Packagist](https://img.shields.io/packagist/v/plan2net/playwright-toolkit)](https://packagist.org/packages/plan2net/playwright-toolkit)
[![TYPO3](https://img.shields.io/badge/TYPO3-11.5%20%7C%2012.4%20%7C%2013.4%20%7C%2014.3-orange)](https://get.typo3.org)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)](https://www.php.net)
[![licence](https://img.shields.io/badge/licence-GPL--2.0--or--later-blue)](LICENSE)

A TYPO3 extension. It creates one test database per test and provides a ready-made
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

TYPO3 11.5 is ELTS. CI verifies against the last public release, 11.5.41; ELTS
releases are behind credentials and cannot be tested here.

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

For nginx-fpm, run `ddev config --nginx-full` and edit
`.ddev/nginx_full/nginx-site.conf`:

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

### Database selection

Point TYPO3 at the test database of the current request in
`config/system/additional-testing.php`. If the project has no such file yet, one line
is enough:

```php
<?php

Plan2net\PlaywrightToolkit\TestContext::applyDatabaseConnectionOverrides();
```

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
the prepared backend session, and stores the API secret in
`var/playwright/api-secret`. Every test database is a copy of this template.

`ddev playwright` runs this step for you, so you rarely call it directly.

To check that a project is set up correctly, run this in the web container:

```bash
BASE_URL=https://example-testing.ddev.site Tests/Smoke/health-and-session.sh
```

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

The backend shows the test ID next to the site name in the top left corner, so two
open tabs are never confused:

```
PlaywrightDemo [EF70E3DDD33D3571]
```

Nothing to configure; it appears whenever a request carries a test ID.

The link is signed with the API secret and lives **15 minutes**. It sets two
session cookies, so closing the browser ends the visit.

## Troubleshooting

**Every endpoint answers 404.** The request did not reach the Testing context. Open
the testing host name, not the normal one, and check your web server configuration.

**Tests write to your normal database.** Your project merges the override paths as
array keys. See the note under Database selection.

**The run stops with "run ddev playwright-prepare".** The template database is
missing or was built with different settings. Run `ddev playwright-prepare` again.

**Every endpoint answers 401.** The npm package and PHP do not share the secret
file. If they run in different containers, set `PLAYWRIGHT_TOOLKIT_SECRET` to the
same value on both sides.

## Related packages

- [`@plan2net/typo3-playwright-toolkit`](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/typo3-playwright-toolkit) — npm package
- [DDEV add-on](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/ddev-typo3-playwright-toolkit) — database service and commands
