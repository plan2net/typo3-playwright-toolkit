<p align="center">
  <img src="https://raw.githubusercontent.com/plan2net/typo3-playwright-toolkit/main/packages/playwright-toolkit/Resources/Public/Icons/Extension.svg" alt="" width="96" height="96">
</p>
<h1 align="center">plan2net/playwright-toolkit</h1>
<p align="center"><em>One test database per test file, and a ready-made backend session, for TYPO3.</em></p>
<br>

<p align="center">
  <a href="https://packagist.org/packages/plan2net/playwright-toolkit"><img src="https://img.shields.io/packagist/v/plan2net/playwright-toolkit?style=for-the-badge&logo=packagist&logoColor=white&labelColor=24273a&color=fff3b0" alt="Packagist version"></a>
  <a href="https://get.typo3.org"><img src="https://img.shields.io/badge/TYPO3-11.5%20%7C%2012.4%20%7C%2013.4%20%7C%2014.3-ffb997?style=for-the-badge&logo=typo3&logoColor=white&labelColor=24273a" alt="TYPO3 11.5, 12.4, 13.4 and 14.3"></a>
  <a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-8.1%2B-c3b1e1?style=for-the-badge&logo=php&logoColor=white&labelColor=24273a" alt="PHP 8.1 or newer"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/licence-GPL--2.0--or--later-ffc6d9?style=for-the-badge&logo=gnu&logoColor=white&labelColor=24273a" alt="GPL-2.0-or-later licence"></a>
</p>

A [TYPO3](https://typo3.org) extension. It creates one test database per test file
and provides a ready-made backend session, so tests never fill in the login form.

Developed in the
[typo3-playwright-toolkit monorepo](https://github.com/plan2net/typo3-playwright-toolkit);
`plan2net/playwright-toolkit` is a read-only mirror that Packagist reads. Open issues
and pull requests on the monorepo. A commit pushed to the mirror is overwritten by the
next release.

It needs the
[npm package](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/typo3-playwright-toolkit),
which runs the tests, and the
[DDEV add-on](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/ddev-typo3-playwright-toolkit),
which provides the database service.

> [!IMPORTANT]
> Setting this up for the first time? Follow
> **[SETUP.md](https://github.com/plan2net/typo3-playwright-toolkit/blob/main/SETUP.md)**
> instead. It covers all three packages in order. This README documents the extension
> on its own.

[Requirements](#requirements) · [Install](#install) · [Configure](#configure) ·
[Using the package](#using-the-package) · [Reference](#reference) ·
[Troubleshooting](#troubleshooting) · [Related packages](#related-packages)

## Requirements

- TYPO3 11.5, 12.4, 13.4 or 14.3
- PHP 8.1 or newer
- PostgreSQL, MySQL, MariaDB or SQLite

TYPO3 11.5 and 12.4 are both ELTS. CI verifies each against its last public release,
11.5.41 and 12.4.45, because ELTS releases sit behind credentials and cannot be
tested here.

## Install

```bash
composer require --dev plan2net/playwright-toolkit
```

Every part of the extension first checks whether TYPO3 runs in the Testing context,
so it does nothing in Production and Development. Install it as a `--dev` dependency
anyway: that check is not a reason to ship it to production.

Then let the wizard do the rest:

```bash
ddev exec 'TYPO3_CONTEXT=Testing vendor/bin/typo3 playwright:setup'   # once the add-on is installed: ddev playwright setup
```

It runs every check of
[SETUP.md](https://github.com/plan2net/typo3-playwright-toolkit/blob/main/SETUP.md),
writes the files that are missing and builds the template database. That guide
explains the command, its `--no-interaction` mode, and why it has to run in the
Testing context. It needs DDEV; without it, follow
[Without DDEV](https://github.com/plan2net/typo3-playwright-toolkit/blob/main/SETUP.md#without-ddev).

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

> [!IMPORTANT]
> A `.ddev/nginx/*.conf` file does not work here. DDEV includes those after the PHP
> location block, and nginx then ignores the value.

A complete file is checked in at
[`tests/e2e/consumer/.ddev/nginx_full/nginx-site.conf`](https://github.com/plan2net/typo3-playwright-toolkit/blob/main/tests/e2e/consumer/.ddev/nginx_full/nginx-site.conf).
Copy from there.

Do not write that marker anywhere else in the file, not even in a comment: DDEV
searches the whole file for it. Then run `ddev restart`.

### Database selection

Point TYPO3 at the test database of the current request from
`config/system/additional.php`. TYPO3 auto-loads that file and no context-suffixed
variant, so this is the place:

```php
<?php

if (\TYPO3\CMS\Core\Core\Environment::getContext()->isTesting()) {
    \Plan2net\PlaywrightToolkit\TestContext::configureCurrentRequest();
}
```

Without those lines the overrides never run and every test uses your ordinary
database. Keep the context check: `configureCurrentRequest()` acts on the test ID
alone, so outside the Testing context a request carrying that header would switch the
connection on your ordinary hostname too.

> [!IMPORTANT]
> Under DDEV this file already exists and carries `#ddev-generated`. Delete that line
> first, as with the nginx file, or the next `ddev restart` writes the file again
> without your call, and your tests then pass against your ordinary database.
>
> Put the call at the end of the file. It reads the `Default` connection, which DDEV
> sets in the block above it.

If your project already keeps a separate file per context, put the call in the
Testing one and require that from `additional.php` behind the same check.

> [!NOTE]
> `config/system/additional.php` is the Composer-mode path, which is where TYPO3 12.4,
> 13.4 and 14.3 look. Two older layouts differ, and only the file name changes; the
> contents above are the same:
>
> - TYPO3 11.5 loads `typo3conf/AdditionalConfiguration.php`, in Composer mode too.
> - Classic (non-Composer) 12.4 and 13.4 load `typo3conf/system/additional.php`.
>
> `ConfigurationManager::getAdditionalConfigurationFileLocation()` is the authority if
> you need to check a version not listed here.

It reads your `Default` connection and writes the per-test one back. If a request
carries no test ID, nothing changes and nothing is created: the site uses its normal
database.

#### Which of the two calls

There are two, and the only question they answer is who writes `$GLOBALS`:

| Your `additional.php` | Call |
|---|---|
| writes `$GLOBALS` itself | `configureCurrentRequest()`, as above |
| collects settings in an array and applies them at the end | `resolveCurrentRequestSettings()`, merged in last |

They do the same work otherwise. Both pick the test database, create it as part of
answering, and switch on the error capture behind `typo3-errors.json`.

If your project collects its settings in an array that is applied afterwards, the
one-line call above is overwritten again. Merge the settings into that array instead,
last:

```php
$configurationSettings = array_merge(
    $configurationSettings,
    TestContext::resolveCurrentRequestSettings($GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? [])
);
```

> [!NOTE]
> If that array also carries your database credentials, which is common when they
> come from environment variables rather than `settings.php`, then `$GLOBALS` does
> not name a driver yet at this point, and the call throws
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
>     TestContext::resolveCurrentRequestSettings($defaultConnection)
> );
> ```
>
> `configureCurrentRequest($defaultConnection)` takes the same argument, for
> projects that write to `$GLOBALS` directly.

#### Two things to get right

Whichever call you use:

1. `SYS/encryptionKey` must already hold the key your test databases were prepared
   with.
2. The returned `DB/Connections/Default/*` values are paths, not array keys, and have
   to land last.

On the first: the toolkit hashes the pre-seeded session id with that key to tell an
already-seeded database from a new one, and it does so before TYPO3 boots. Two setups
get it wrong: a Testing configuration using a different key than the rest of the
site, and one that collects its settings in an array and writes them to `$GLOBALS`
only afterwards. In both, assign the key before the call:

```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = '…the key playwright:prepare uses…';
```

Otherwise the lookup finds nothing, every request treats its database as unseeded and
clones it again, and the content the test just built is gone.

On the second: a returned key looks like `DB/Connections/Default/dbname`. Write it
with `ArrayUtility::setValueByPath`, or the helper your project already uses for its
other settings, and it lands correctly. A plain `array_merge` into
`$GLOBALS['TYPO3_CONF_VARS']` creates one key with that literal name, and your tests
then run against your real database.

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

Keep the manifest to what every test needs: a site root, a TypoScript template, the
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
processed images, `fileadmin/_processed_<test id>`, and every conversion gets a
scratch name of its own in `typo3temp/assets/images/`, where TYPO3 works before
moving the result into that folder. Both carry the test ID, so nothing is shared
between tests and both go when the test database does.

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
| `fixturesPath` | none | Folder with your SQL fixture files, relative to the project root |
| `fixtureManifest` | none | Fixture file names, separated by commas, loaded in this order |
| `preseededSessionId` | `playwright_test_session` | Backend session ID stored in the template database |
| `sessionUserId` | `1` | Backend user this session belongs to |
| `cleanupMinimumAgeMs` | `3600000` | How old a test database must be before cleanup may delete it |

If you change `fixturesPath`, `fixtureManifest` or the session settings, the next run
rebuilds the template database.

`sessionUserId` decides who saves the content your tests build, so page permissions,
table access and mounts all apply to it. Fixtures are applied before the session is
seeded, and the seeded user is written with `INSERT IGNORE`, so a `be_users` row of
your own at that uid wins. Without one, the toolkit writes an admin there.

### Endpoints

All endpoints start with `/typo3/test-api/`, need the
`X-Playwright-Toolkit-Secret` header, and answer `404` outside the Testing context.
[CONTRACT.md](https://github.com/plan2net/typo3-playwright-toolkit/blob/main/CONTRACT.md)
describes them.

The one exception is `inspect`, which a browser opens. It takes a signed link
instead of the header, because a browser cannot send one. See
[Looking at a kept database](#looking-at-a-kept-database).

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
There is nothing to configure; the marker appears whenever a request carries a test
ID.

The link is signed with the API secret and lives 15 minutes. It sets two session
cookies, so closing the browser ends the visit.

### What TYPO3 recorded during a test

While a test runs, TYPO3 writes its own errors into that test's database, and the
endpoint hands them back:

```
GET /typo3/test-api/errors?id=<testId>
```

Records DataHandler refused, uncaught exceptions, and anything logged at error level
or worse, with the message already filled in. Repeats are counted rather than listed
again.

Some problems never reach the log, so they cannot show up here:

- PHP fatal errors, such as running out of memory or hitting the time limit.
- The few exceptions TYPO3 skips on purpose, like a wrong host header or a blocked
  login attempt.
- Anything that goes wrong while the database itself is broken.
- A relation that ended up empty. TYPO3 reports records it rejected, not records it
  saved with a link pointing nowhere.

### Replay

`typo3 playwright:replay-prepare` rebuilds the replay database from the template. It
is the plain `db` on the `db-test` container, reached through the fixed test ID
`REPLAY0000000000`, and `ddev playwright-replay` calls it before running every
scenario's setup into that one database. The
[npm README](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/typo3-playwright-toolkit#replay-mode)
describes replay mode.

## Troubleshooting

**Every endpoint answers 404.** The request did not reach the Testing context. Open
the testing host name, not the normal one, and check your web server configuration.

**Tests write to your normal database.** This has three causes. Your project merges
the override paths as array keys; DDEV rewrote `additional.php` because its
`#ddev-generated` marker is still there (see Database selection); or something strips
the test ID header on the way to PHP: `fastcgi_pass_request_headers off` in nginx, a
`RequestHeader unset` or a mod_security rule in Apache, or a proxy in front of the
testing hostname. TYPO3 then sees an ordinary request and answers it from the site's
own database, so the tests pass against the wrong content.

**The run stops with "run ddev playwright-prepare".** The template database is
missing or was built with different settings. Run `ddev playwright-prepare` again.

**Every endpoint answers 401.** The npm package and PHP do not share the secret
file. If they run in different containers, set `PLAYWRIGHT_TOOLKIT_SECRET` to the
same value on both sides.

## Related packages

- [`@plan2net/typo3-playwright-toolkit`](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/typo3-playwright-toolkit), the npm package
- [DDEV add-on](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/ddev-typo3-playwright-toolkit), the database service and commands
