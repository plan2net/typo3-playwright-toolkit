# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

One tag releases all three packages, so this file covers them together and names
the package a change belongs to.

## [Unreleased]

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

[Unreleased]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.4.1...main
[0.4.1]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/plan2net/typo3-playwright-toolkit/releases/tag/v0.1.0
