# ddev-typo3-playwright-toolkit

[![e2e](https://github.com/plan2net/typo3-playwright-toolkit/actions/workflows/e2e.yml/badge.svg)](https://github.com/plan2net/typo3-playwright-toolkit/actions/workflows/e2e.yml)
[![DDEV](https://img.shields.io/badge/DDEV-1.25%2B-02c7e6)](https://ddev.com)
[![databases](https://img.shields.io/badge/databases-PostgreSQL%20%7C%20MySQL%20%7C%20MariaDB-336791)](#requirements)
[![licence](https://img.shields.io/badge/licence-GPL--2.0--or--later-blue)](LICENSE)

A DDEV add-on. It installs the database service that holds the test databases, and
the `ddev playwright` commands that run your tests.

It needs the [Composer extension](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/playwright-toolkit), which creates the test
databases, and the [npm package](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/typo3-playwright-toolkit), which contains the
test helpers.

[Requirements](#requirements) · [Install](#install) · [Configure](#configure) ·
[Using the package](#using-the-package) · [Reference](#reference) ·
[Troubleshooting](#troubleshooting) · [Related packages](#related-packages)

## Requirements

- DDEV 1.25.0 or newer
- A PostgreSQL, MySQL or MariaDB project. Install picks the matching `db-test`
  service. An SQLite project needs no service at all.
- The Composer extension, installed in the project
- A host name that runs in the Testing context. The add-on does not set this up; see
  the [extension README](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/playwright-toolkit#testing-host).
- Playwright with browsers, in the container where `ddev playwright` runs. Install
  them with the `lullabot/ddev-playwright` add-on, your own container, or
  `npx playwright install` in the web container.

The add-on does not change your web server configuration. Apache and nginx pass the
test ID header to PHP by themselves.

## Install

```bash
ddev add-on get https://github.com/plan2net/typo3-playwright-toolkit/releases/latest/download/ddev-typo3-playwright-toolkit.tar.gz
ddev restart
```

Important: use this release archive, not `ddev add-on get plan2net/…`. DDEV expects
`install.yaml` at the top level of a repository, and this add-on lives in a subfolder
of a monorepo.

## Configure

The test databases need no configuration. `ddev playwright` prepares the template
database itself before every test run.

### Where your tests live

The commands run in `tests/playwright`. If yours are somewhere else, say so in
`.ddev/config.yaml` and `ddev restart`:

```yaml
web_environment:
    - PW_TEST_DIR=e2e
```

### Browsers in a container of their own

Browsers run in the web container by default. To run them somewhere else — another
image, another architecture — start a Playwright server and point the test run at
it in `.ddev/config.yaml`:

```yaml
web_environment:
    - PW_TEST_CONNECT_WS_ENDPOINT=ws://playwright-server:3000/
    - PW_TEST_CONNECT_EXPOSE_NETWORK=*
```

Playwright reads both variables itself, so the commands need no flag and no change.
Only the browser moves: the test run stays in the web container with your
`node_modules`, the API secret and the state directory, and
`PW_TEST_CONNECT_EXPOSE_NETWORK` tunnels the browser's requests back out through
it — so the browser container needs no route to your site and no DDEV certificate.

The server is one container of your own, in `.ddev/docker-compose.playwright-server.yaml`:

```yaml
services:
    playwright-server:
        image: mcr.microsoft.com/playwright:v1.61.1-noble
        command:
            ['npx', '-y', 'playwright@1.61.1', 'run-server', '--port', '3000', '--host', '0.0.0.0']
```

The version has to match the `@playwright/test` your project installed. Add
`platform: linux/amd64` if you compare screenshots across machines: rasterisation
happens where the browser runs, so an arm64 laptop and an amd64 runner disagree on
the same page.

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
| `PLAYWRIGHT_DB_TEST_USER` | `db` | User name |
| `PLAYWRIGHT_DB_TEST_PASSWORD` | `db` | Password |

There is no setting for the port. The extension uses the default port of your
database system.

### UI mode port

UI mode runs a web application inside the container, and the add-on opens port 3000
for it in `config.playwright-toolkit.yaml`. `PW_UI_PORT` changes the port, but you
then have to change it in that file as well.

## Using the package

`ddev playwright` passes everything on to `npx playwright`, so every Playwright
argument works:

```bash
ddev playwright test                 # all tests
ddev playwright test accordion       # one file
ddev playwright show-report
```

Before `test`, the command clears the Testing caches and rebuilds the template
database, so a change to TCA or `ext_tables.sql` never runs against an old schema.

When a test fails its database is kept, and the run prints a link for it. To get a
link later, or for a specific file:

```bash
ddev playwright-inspect            # every kept database
ddev playwright-inspect accordion  # one test file
```

Opening a link logs you into the TYPO3 backend of that database, and the frontend is
reachable from there. Links are signed and expire after 15 minutes.

UI mode runs in the web container, so the browsers must be installed there:

```bash
ddev playwright-ui              # all tests
ddev playwright-ui accordion    # one test file
```

Then open `https://<project>.ddev.site:3000`.

## Reference

### Commands

| Command | Purpose |
|---|---|
| `ddev playwright` | Runs `npx playwright` with the arguments you pass |
| `ddev playwright-inspect` | Prints links that open a kept test database in the backend |
| `ddev playwright-prepare` | Builds the template database on its own |
| `ddev playwright-ui` | Serves Playwright UI mode from the web container |

### Flags

`ddev playwright` handles these itself and does not pass them on. They are flags and
not environment variables, because a variable typed on the host never reaches the
container.

| Name | Default | Purpose |
|---|---|---|
| `--build` | off | Runs `npm run build` before the tests |
| `--no-cleanup` | off | Keeps the test databases and state files after the run |
| `--skip-prepare` | off | Reuses the existing template database instead of rebuilding it |

### Environment variables

These must be set in `web_environment` or `.ddev/.env.web`. Typing them on the host
does not work, because these are DDEV web commands.

| Name | Default | Purpose |
|---|---|---|
| `PW_TEST_DIR` | `tests/playwright` | Where your Playwright tests live, relative to the project root |
| `PW_RUN_ID` | generated | Names the run, so cleanup can tell runs apart |
| `PW_UI_PORT` | `3000` | Port for UI mode |
| `PW_SKIP_PREPARE` | unset | Same as `--skip-prepare`; use the flag instead |
| `PW_TEST_CONNECT_WS_ENDPOINT` | unset | Browser server to drive instead of the local browsers — see [Browsers in a container of their own](#browsers-in-a-container-of-their-own) |
| `PW_TEST_CONNECT_EXPOSE_NETWORK` | unset | Lets that browser reach your site through the web container; `*` covers everything |

The last two are Playwright's own, read by the test run rather than by this add-on,
so they work the same way outside DDEV.

## Troubleshooting

**Playwright reports a missing browser.** The browsers are not installed in the
container that runs the command. Run `ddev exec 'npx playwright install --with-deps'`.

**The first test fails with `ERROR 1044 Access denied`.** The MySQL or MariaDB user
cannot create databases. Reinstall the add-on, which grants the missing rights.

**Tests do not see your latest TCA change.** The template database was reused. Drop
`--skip-prepare`, and check that `PW_SKIP_PREPARE` is not set in `web_environment`.

## Related packages

- [`plan2net/playwright-toolkit`](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/playwright-toolkit) — Composer extension
- [`@plan2net/typo3-playwright-toolkit`](https://github.com/plan2net/typo3-playwright-toolkit/tree/main/packages/typo3-playwright-toolkit) — npm package
