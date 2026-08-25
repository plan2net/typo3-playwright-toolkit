# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

One tag releases all three packages, so this file covers them together and names
the package a change belongs to.

## [Unreleased]

### Added

- **ddev-typo3-playwright-toolkit** — DDEV add-on shipping the tuned `db-test`
  service and the `playwright`, `playwright-prepare` and `playwright-ui`
  commands. It touches no webserver configuration.
- **playwright-toolkit** — TYPO3 extension (`playwright_toolkit`) for
  TYPO3 11.5 (ELTS), 12.4, 13.4 and 14.3 on PHP 8.1 and up: a throwaway database per test
  cloned from a fingerprinted template, a pre-seeded backend session and a real
  `record_edit` route token, plus health, records, inspect and cleanup endpoints.
  Every endpoint is gated on the Testing context and then on `TestApiSecret`.
- **@plan2net/typo3-playwright-toolkit** — Playwright fixtures and helpers: the
  `definePair` setup/test pattern, page and content builders for every core
  CType, run bookkeeping, screenshot and accessibility checks, and the global
  setup and teardown that provision and drop the test databases over HTTP.
- `CONTRACT.md` and the `contract/` response fixtures, which pin the wire shape
  both packages depend on.

[Unreleased]: https://github.com/plan2net/typo3-playwright-toolkit/commits/main
