# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

One tag releases all three packages, so this file covers them together and names
the package a change belongs to.

## [Unreleased]

### Changed

- **plan2net/playwright-toolkit** — locking now uses `symfony/lock` instead of
  `flock()` calls spread over five places. `LockFiles` offers `shared()`,
  `exclusively()` and `exclusivelyWithin()`, so no caller opens a file handle.
  Lock file names never start with `db-`, which belongs to the claim files
  cleanup looks for.

### Fixed

- **DDEV add-on** — `ddev playwright-prepare` (and every command that reaches the
  test database) now refuses with "run `ddev restart`" when the db-test service is
  configured but not yet active in the web container — running prepare between
  `ddev add-on get` and the enabling `ddev restart` used to fail with a bare
  connection error, or worse: on a host with several toolkit projects, `db-test`
  resolves to *another* project's service on the shared ddev network and prepare
  silently wrote into that project's template.

### Changed

- **plan2net/playwright-toolkit** — the per-test database is now created *before*
  TYPO3 boots, in the same call that redirects the Default connection
  (`TestContext::applyDatabaseConnectionOverrides()`), instead of in a
  `BootCompletedEvent` listener. Any extension that queries the database from its
  own `BootCompletedEvent` listener used to hit a database that did not exist yet
  whenever its listener happened to run before the toolkit's; listener order
  between unrelated extensions is undefined, so this worked in one project and
  500ed in another. The one check
  that needs a booted TYPO3, comparing the template fingerprint against the
  current TCA, stays at `BootCompletedEvent` in the new `TemplateDriftGuard`. It
  runs for every request that reached provisioning, and is paid once per database:
  a marker file records that the template behind it was checked. The processed-folder isolation moved
  into the drivers (`isolateProcessedFiles`).

- **plan2net/playwright-toolkit** — `playwright:prepare` skips the rebuild when the
  stored template fingerprint matches the current schema, fixtures and session seed,
  so an unchanged template no longer costs 30–60s on every `ddev playwright test`.
  The skip uses the same fingerprint `DatabaseInitializer` gates every run on, so it
  is exactly as safe as the runtime check. `--force` rebuilds anyway, and
  `ddev playwright-prepare` forwards it.
- **@plan2net/typo3-playwright-toolkit** — `defineBasePlaywrightConfig` refuses
  overrides of `globalSetup`, `globalTeardown`, `use.baseURL` and
  `use.serviceWorkers` instead of silently accepting them: the hooks carry the
  preflight, run bookkeeping and database cleanup (including leak detection), and
  the other two are the header-routing invariants. The new
  `BasePlaywrightOverrides` type excludes them, and a runtime check catches plain-JS
  consumers with an actionable error.

## [0.4.2] - 2026-08-27

### Fixed

- **plan2net/playwright-toolkit** — the scratch name for an image conversion is now
  unique per conversion. 0.4.1 made it unique per test, which still let two parallel
  requests of the same test collide: one renames the file away while the other is
  reading it, and TYPO3 then serves the **original** image for the rest of that test
  database's life, which is a subtly wrong screenshot rather than a failed test.
  A browser opens parallel requests for a page's images, so this is the common case.

## [0.4.1] - 2026-08-27

### Fixed

- **plan2net/playwright-toolkit** — two tests converting the same image no longer
  collide. TYPO3 writes a conversion to `typo3temp/assets/images/` and renames it
  from there into the processing folder, and it names that scratch file after the
  image and the conversion, not after the test. The second test found the first
  one's file, skipped its own conversion because the file was already there, and
  then found it renamed away; TYPO3 marked the task unprocessed and served the
  **original** image for the rest of that test database's life, which is a subtly
  wrong screenshot rather than a failed test. Those names now carry the test ID and
  are removed with the test's other files. The per-test processing folder added in
  0.4.0 could not prevent this: the collision happens before that folder is reached.

## [0.4.0] - 2026-08-27

### Fixed

- **plan2net/playwright-toolkit** — each test database now gets its own folder for
  processed images, `fileadmin/_processed_<test id>`. The records naming those images
  live in the database, so every test used to regenerate the same shared files and
  could overwrite one while another test was reading it. That showed up as a test
  seeing the wrong crop of the right image, and only under load. The folder goes when
  its database is dropped, so a database kept after a failure — and the replay one —
  keeps its images.
- **plan2net/playwright-toolkit** — the name check that guards `DROP DATABASE` no
  longer accepts a trailing newline. PHP's `$` matches before one, so a test ID sent
  with `\n` appended passed a pattern meant to allow only sixteen characters.
- **@plan2net/typo3-playwright-toolkit** — when the preflight gets an answer it
  cannot parse, it now prints that answer. It used to say the extension was not
  loaded, which is often wrong: a PHP error in your own configuration also lands
  here, and its message tells you what broke.
- **plan2net/playwright-toolkit** — `applyDatabaseConnectionOverrides()` now accepts
  the Default connection as an argument. It only read `$GLOBALS` before, which is
  still empty if your database settings come from environment variables, so
  provisioning failed with `The Default database connection names no driver`. The
  README shows what to pass.

## [0.3.0] - 2026-08-26

### Breaking

- **@plan2net/typo3-playwright-toolkit** — `takeScreenshot` is now
  `expectScreenshot`. It asserts against a stored baseline and fails the test on a
  mismatch, which the old name hid: `takeScreenshot` reads like Playwright's own
  `page.screenshot()`, which captures an image and asserts nothing. The package is
  alpha, so the old name is gone rather than deprecated. Rename the import and the
  calls; nothing else about it changed.

### Documentation

- The example on the landing page ends with a screenshot assertion, so the sample
  shows the visual check the waiting logic exists for rather than only a text
  assertion.

## [0.2.0] - 2026-08-26

### Added

- **ddev-typo3-playwright-toolkit** — `ddev playwright-replay` runs every scenario's
  setup into one database instead of one per test file, so everything the suite
  builds ends up in a single place you can click through or export. Each scenario
  writes into a sysfolder named after its file. The tests themselves are skipped:
  their assertions and screenshot baselines belong to a per-test database. The run
  prints a backend link when it finishes. Sample pages nobody had to build by hand.
- **ddev-typo3-playwright-toolkit** — `ddev playwright-inspect --replay` mints a
  fresh link into that database when the one the run printed has expired.
- **playwright-toolkit** — `typo3 playwright:replay-prepare` rebuilds the replay
  database from the template. The add-on command calls it; the fixed test ID
  `REPLAY0000000000` maps to the plain `db` on the `db-test` container.
- **@plan2net/typo3-playwright-toolkit** — `saveRecord()` in the `defineScenario`
  setup tools writes a row in a table no builder covers and returns the uid TYPO3
  assigned. The `RecordToSave` type is exported.
- **@plan2net/typo3-playwright-toolkit** — screenshot calls take a `hide` option
  that replaces `hideBeforeScreenshot` for that shot; `[]` hides nothing.

### Changed

- **@plan2net/typo3-playwright-toolkit** — `PageBuilder` refuses a second page with
  a slug already used in the same scenario, instead of letting TYPO3 suffix it and
  leaving the test to navigate somewhere unexpected.

### Documentation

- The setup is written as one file: `config/system/additional.php` calls
  `TestContext::applyDatabaseConnectionOverrides()` behind the context check. The
  separate `additional-testing.php` was a habit of the docs and the example project,
  never a requirement, and both now show the shorter form.
- The extension README's backend marker example now shows the scenario name beside
  the test ID, which is what the backend has printed since 0.1.0.

- Browsers: how to install them, and `PLAYWRIGHT_BROWSERS_PATH` so a container
  rebuild does not throw them away.
- Why a second Playwright add-on cannot be installed beside this one: both ship
  `commands/web/playwright`, and the later install wins.
- The Playwright directory is yours to create; the setup step is one command so it
  cannot be half-copied.
- A landing page at <https://plan2net.github.io/typo3-playwright-toolkit/>.

## [0.1.0] - 2026-08-26

### Added

- **ddev-typo3-playwright-toolkit** — DDEV add-on shipping the tuned `db-test`
  service and the `playwright`, `playwright-prepare`, `playwright-ui` and
  `playwright-inspect` commands. It touches no webserver configuration.
- **playwright-toolkit** — TYPO3 extension (`playwright_toolkit`) for
  TYPO3 11.5 and 12.4 (both ELTS), 13.4 and 14.3 on PHP 8.1 and up: a throwaway database per test
  cloned from a fingerprinted template, a pre-seeded backend session and a real
  `record_edit` route token, plus health, records, inspect and cleanup endpoints.
  Every endpoint is gated on the Testing context and then on `TestApiSecret`.
- **@plan2net/typo3-playwright-toolkit** — Playwright fixtures and helpers: the
  `defineScenario` setup/test pattern, page and content builders for every core
  CType, run bookkeeping, screenshot and accessibility checks, and the global
  setup and teardown that provision and drop the test databases over HTTP.
- `CONTRACT.md` and the `contract/` response fixtures, which pin the wire shape
  both packages depend on.

[Unreleased]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.4.2...main
[0.4.2]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.4.1...v0.4.2
[0.4.1]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/plan2net/typo3-playwright-toolkit/releases/tag/v0.1.0
