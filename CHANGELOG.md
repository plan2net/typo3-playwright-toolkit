# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

One tag releases all three packages, so this file covers them together and names
the package a change belongs to.

## [Unreleased]

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

[Unreleased]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.2.0...main
[0.2.0]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/plan2net/typo3-playwright-toolkit/releases/tag/v0.1.0
