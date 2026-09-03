<p align="center">
  <img src="https://raw.githubusercontent.com/plan2net/typo3-playwright-toolkit/main/packages/playwright-toolkit/Resources/Public/Icons/Extension.svg" alt="" width="96" height="96">
</p>
<h1 align="center">ddev-typo3-playwright-toolkit</h1>
<p align="center"><em>The test-database service and the ddev playwright commands.</em></p>
<br>

<p align="center">
  <a href="https://github.com/plan2net/typo3-playwright-toolkit/actions/workflows/e2e.yml"><img src="https://img.shields.io/github/actions/workflow/status/plan2net/typo3-playwright-toolkit/e2e.yml?branch=main&style=for-the-badge&logo=githubactions&logoColor=white&label=e2e&labelColor=24273a" alt="e2e"></a>
  <a href="https://ddev.com"><img src="https://img.shields.io/badge/DDEV-1.25%2B-a0c4ff?style=for-the-badge&labelColor=24273a&logo=data:image/svg%2Bxml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjZmZmIiBzdHJva2Utd2lkdGg9IjIuMiIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIj48cGF0aCBkPSJNMTIgMyAyIDhsMTAgNSAxMC01eiIvPjxwYXRoIGQ9Im0yIDEzIDEwIDUgMTAtNSIvPjxwYXRoIGQ9Im0yIDE4IDEwIDUgMTAtNSIvPjwvc3ZnPg%3D%3D" alt="DDEV 1.25 or newer"></a>
  <a href="#requirements"><img src="https://img.shields.io/badge/databases-PostgreSQL%20%7C%20MySQL%20%7C%20MariaDB-c3b1e1?style=for-the-badge&logo=postgresql&logoColor=white&labelColor=24273a" alt="PostgreSQL, MySQL or MariaDB"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/licence-GPL--2.0--or--later-ffc6d9?style=for-the-badge&logo=gnu&logoColor=white&labelColor=24273a" alt="GPL-2.0-or-later licence"></a>
</p>

A [DDEV](https://ddev.com) add-on. It installs the database service that holds the
test databases, and the `ddev playwright` commands that run your
[Playwright](https://playwright.dev) tests.

It needs the [Composer extension](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/playwright-toolkit),
which creates the test databases, and the
[npm package](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/typo3-playwright-toolkit),
which contains the test helpers.

> [!IMPORTANT]
> Setting this up for the first time? Follow
> **[SETUP.md](https://github.com/plan2net/typo3-playwright-toolkit/blob/main/SETUP.md)**
> instead. It covers all three packages in order. This README documents the add-on
> on its own.

[Requirements](#requirements) · [Install](#install) · [Configure](#configure) ·
[Using the package](#using-the-package) · [Reference](#reference) ·
[Troubleshooting](#troubleshooting) · [Related packages](#related-packages)

## Requirements

- DDEV 1.25.0 or newer
- A [PostgreSQL](https://www.postgresql.org), [MySQL](https://www.mysql.com) or
  [MariaDB](https://mariadb.org) project. Install picks the matching `db-test`
  service. An [SQLite](https://sqlite.org) project needs no service at all.
- The Composer extension, installed in the project
- A host name that runs in the Testing context. The add-on does not set this up; see
  the [extension README](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/playwright-toolkit#testing-host).
- Playwright with browsers, in the container where `ddev playwright` runs; see
  [browsers](#browsers). Do not use another Playwright add-on for this; see
  [other Playwright add-ons](#other-playwright-add-ons). The browsers can also run
  elsewhere, and so can the whole test run:
  [Where things run](https://github.com/plan2net/typo3-playwright-toolkit/blob/main/SETUP.md#where-things-run)
  covers both.
- A directory for your tests. The add-on does not create one; see
  [where your tests live](#where-your-tests-live).

The add-on does not change your web server configuration. Apache and nginx pass the
test ID header to PHP by themselves.

## Install

```bash
ddev add-on get https://github.com/plan2net/typo3-playwright-toolkit/releases/latest/download/ddev-typo3-playwright-toolkit.tar.gz
ddev restart
```

> [!IMPORTANT]
> Use this release archive, not `ddev add-on get plan2net/…`. DDEV expects
> `install.yaml` at the top level of a repository, and this add-on lives in a
> subfolder of a monorepo.

## Configure

The test databases need no configuration. `ddev playwright` prepares the template
database itself before every test run.

### Where your tests live

The commands run in `tests/playwright`. The add-on does not create it. It is your
directory, with your own `package.json`:

```bash
mkdir -p tests/playwright && cd tests/playwright
ddev npm init -y && ddev npm pkg set type=module
ddev npm i -D @plan2net/typo3-playwright-toolkit @playwright/test
```

`ddev npm` runs in the container directory matching the one you are standing in, so
there is no `cd` inside the container to get right.

Tests somewhere else? Say so in `.ddev/config.yaml` and `ddev restart`:

```yaml
web_environment:
    - PW_TEST_DIR=e2e
```

### Browsers

The add-on ships none. Install them in the web container, where the tests run:

```bash
cd tests/playwright && ddev npx playwright install --with-deps chromium
```

They land in the container's home directory, which DDEV drops on a rebuild. Keep them
under the project instead, `ddev restart`, and gitignore `.cache/`:

```yaml
web_environment:
    - PLAYWRIGHT_BROWSERS_PATH=/var/www/html/.cache/ms-playwright
```

This is one of three layouts. Put the browsers in a container of their own and these
commands keep working unchanged. Move the test run there as well and they no longer
apply, because they are DDEV web commands and run where PHP is.
[Where things run](https://github.com/plan2net/typo3-playwright-toolkit/blob/main/SETUP.md#where-things-run)
has both.

### Other Playwright add-ons

Do not install one beside this. `lullabot/ddev-playwright` ships the same file,
`commands/web/playwright`, so the second install replaces the first `ddev playwright`.
If theirs wins, a run no longer rebuilds the database template first and looks in
`test/playwright` rather than `PW_TEST_DIR`.

> [!NOTE]
> What it offers beyond this add-on is browser installation and a KasmVNC desktop for
> watching runs. You need neither: browsers are one command, above, and
> `ddev playwright-ui` serves Playwright's own UI mode on the exposed port 3000, which
> you open in your host browser.

### A different database system

Install picks the `db-test` service that matches your project. If you change your
project's database system afterwards, run the selection again with the database type
and version:

```bash
cd .ddev && bash db-test/select-service.sh postgres:16
```

### SQLite

An SQLite project needs no service. Delete `.ddev/docker-compose.db-test.yaml` and
set `pdo_sqlite` in the Testing context. Test databases are then files in
`var/test-databases/`.

### Your own database server

Each service publishes how to reach it. Point these at your own server instead if
you run one:

| Name | Default | Purpose |
|---|---|---|
| `PLAYWRIGHT_DB_TEST_HOST` | `db-test` | Host name of the test database server |
| `PLAYWRIGHT_DB_TEST_PORT` | the engine's own | Port, when your server does not use the default |
| `PLAYWRIGHT_DB_TEST_USER` | `db` | User name |
| `PLAYWRIGHT_DB_TEST_PASSWORD` | `db` | Password |

### UI mode and report ports

Both run a web application inside the container, so `config.playwright-toolkit.yaml`
opens port 3000 for UI mode and 9323 for `ddev playwright show-report`. Each serves on
every interface, so the printed link works from your own browser. `PW_UI_PORT` and
`PW_REPORT_PORT` change the ports, but you then have to change them in that file as
well.

The link names your project's primary hostname. A project with several hostnames can
use any of them, since they all reach the same container.

## Using the package

`ddev playwright` passes everything on to `npx playwright`, so every Playwright
argument works:

```bash
ddev playwright test                 # all tests
ddev playwright test accordion       # one file
ddev playwright show-report
```

Before `test`, the command clears the Testing caches and checks the template
database. It is rebuilt when your schema, fixtures or session settings changed and
reused otherwise, so a change to TCA or `ext_tables.sql` never runs against an old
schema, and an unchanged template costs no build time.

When a test fails its database is kept, and the run prints a link for it. To get a
link later, or for a specific file:

```bash
ddev playwright-inspect            # every kept database
ddev playwright-inspect accordion  # one test file
```

Opening a link logs you into the TYPO3 backend of that database, and the frontend is
reachable from there. Links are signed and expire after 15 minutes.

### Replay

`ddev playwright-replay` runs every scenario's setup into one database on the
`db-test` service, so you can browse everything the suite builds in one backend. The
tests themselves are skipped, and the run ends by printing a link that logs you in.
Your project database is never touched.

```bash
ddev playwright-replay                   # every scenario
ddev playwright-replay --grep accordion  # a subset
```

The [npm README](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/typo3-playwright-toolkit#replay-mode)
describes what changes in replay mode.

### UI mode

```bash
ddev playwright-ui              # all tests
ddev playwright-ui accordion    # one test file
```

Then open `https://<project>.ddev.site:3000`. It serves from the web container, so the
browsers have to be installed there.

## Reference

### Commands

| Command | Purpose |
|---|---|
| `ddev playwright` | Runs `npx playwright` with the arguments you pass |
| `ddev playwright setup` | Sets this project up for Playwright, or checks a setup you have |
| `ddev playwright-inspect` | Prints links that open a kept test database in the backend |
| `ddev playwright-prepare` | Builds the template database on its own; `--force` rebuilds one that is still up to date |
| `ddev playwright-replay` | Replays every scenario's content into one browsable database |
| `ddev playwright-ui` | Serves Playwright UI mode from the web container |

### Flags

`ddev playwright` handles these itself and does not pass them on. They are flags and
not environment variables, because a variable typed on the host never reaches the
container.

| Name | Default | Purpose |
|---|---|---|
| `--no-cleanup` | off | Keeps the test databases and state files after the run |
| `--skip-build` | off | Skips the asset build the toolkit runs before the tests |
| `--skip-prepare` | off | Reuses the existing template database instead of rebuilding it |

The build itself belongs to the npm package, which runs your `package.json` build
script before every run. Set `build` in `defineToolkitConfig` to run something else;
the [npm README](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/typo3-playwright-toolkit#building-your-assets)
covers what it detects and how to turn it off completely.

### Environment variables

These must be set in `web_environment` or `.ddev/.env.web`. Typing them on the host
does not work, because these are DDEV web commands.

| Name | Default | Purpose |
|---|---|---|
| `PW_TEST_DIR` | `tests/playwright` | Where your Playwright tests live, relative to the project root |
| `PW_RUN_ID` | generated | Names the run, so cleanup can tell runs apart |
| `PW_UI_PORT` | `3000` | Port for UI mode |
| `PW_REPORT_PORT` | `9323` | Port for `show-report` |
| `PW_SKIP_BUILD` | unset | Same as `--skip-build`; use the flag instead |
| `PW_SKIP_PREPARE` | unset | Same as `--skip-prepare`; use the flag instead |
| `PW_TEST_CONNECT_WS_ENDPOINT` | unset | Browser server to drive instead of the local browsers; see [Where things run](https://github.com/plan2net/typo3-playwright-toolkit/blob/main/SETUP.md#where-things-run) |
| `PW_TEST_CONNECT_EXPOSE_NETWORK` | unset | Lets that browser reach your site through the web container; `*` covers everything |

The last two are Playwright's own, read by the test run rather than by this add-on,
so they work the same way outside DDEV.

## Troubleshooting

**Playwright reports a missing browser.** The browsers are not installed in the
container that runs the command. Run `ddev npx playwright install --with-deps` from
your Playwright directory.

**The first test fails with `ERROR 1044 Access denied`.** The MySQL or MariaDB user
cannot create databases. Reinstall the add-on, which grants the missing rights.

**Tests do not see your latest TCA change.** The template database was reused. Drop
`--skip-prepare`, and check that `PW_SKIP_PREPARE` is not set in `web_environment`.

**Tests do not see your latest CSS or JavaScript change.** Nothing built the assets.
Check that your `package.json` has a `build` script, or name your build in
`defineToolkitConfig`, and drop `--skip-build`.

## Related packages

- [`plan2net/playwright-toolkit`](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/playwright-toolkit), the Composer extension
- [`@plan2net/typo3-playwright-toolkit`](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/typo3-playwright-toolkit), the npm package
