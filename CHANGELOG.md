# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

One tag releases all three packages, so this file covers them together and names
the package a change belongs to.

## [Unreleased]

## [0.9.0] - 2026-08-30

### Breaking

- **plan2net/playwright-toolkit** — two methods have new names. Change the
  call in your `config/system/additional.php`:

  ```php
  \Plan2net\PlaywrightToolkit\TestContext::configureCurrentRequest();
  ```

  It was `applyDatabaseConnectionOverrides()`. The call now does more than pick the test
  database, so the old name no longer fitted.

  If your project merges the settings itself, use `resolveTestDatabaseConnection()`
  instead of `databaseConnectionOverrides()`. The old name sounded like it only returned
  values, but it creates the test database as well.

  There are no aliases. An old name stops the first test run with "undefined method". A
  live site cannot be affected, because the call only runs in the Testing context.

### Added

- **plan2net/playwright-toolkit** and **@plan2net/typo3-playwright-toolkit** — when a
  test fails, the report now shows what TYPO3 wrote to its log while that test ran:
  records it refused to save, uncaught exceptions, and anything logged as an error. The
  full list is attached to the test as `typo3-errors.json`. A message that repeats is
  counted, not printed again.

- **plan2net/playwright-toolkit** and **@plan2net/typo3-playwright-toolkit** — a builder
  now stops at the line that saved, if TYPO3 refused the record. Until now the save
  looked fine and the test failed later, somewhere else.

- **plan2net/playwright-toolkit** and **@plan2net/typo3-playwright-toolkit** — a scenario
  setup that fails shows the same list, and still shows its own error and the line it
  came from.

### Fixed

- **ddev-typo3-playwright-toolkit** — `ddev playwright test` and `ddev
  playwright-replay` no longer empty `fileadmin/_processed_`. Every test database
  gets its own `_processed_<testId>` folder, which is removed with the database, so
  the deletion only ever hit the images your own site had built.

- **plan2net/playwright-toolkit** — `playwright:prepare` no longer warns about the DDEV
  add-on when no add-on is installed. It read a missing version file as an empty
  version, so a project that runs without DDEV was told on every run to install a
  release it does not use.

### Documentation

- The README says what to do without DDEV, and `SETUP.md` points at it. The add-on
  gives you the `db-test` service and the `ddev playwright*` commands; nothing in the
  other two packages reads anything DDEV-specific. The extension finds the test
  database server through four environment variables, and the npm package only speaks
  HTTP. What you provide instead is a second database server and the two commands the
  wrappers run, and what you give up is proof: CI exercises the DDEV path on every
  push and a setup of your own is not covered by it.

## [0.8.0] - 2026-08-29

### Added

- **@plan2net/typo3-playwright-toolkit** — `withSetting(name, value)` writes one of a
  plugin's `settings.` values. Calls collect, and all of them land in `pi_flexform`
  together:

  ```ts
  element.withSetting('limit', 10).withSetting('order', 'title')
  ```

  `withField('pi_flexform', …)` overwrites, so a builder with two settings had to
  gather them itself and override `getFields()`. Named sheets still take
  `flexForm()`.

- **@plan2net/typo3-playwright-toolkit** — `imageCrops()` writes a crop for each name
  in a column's `cropVariants`:

  ```ts
  imageCrops({ mobile: { ratio: '9:16' }, desktop: { ratio: '16:9' } })
  ```

  `imageCrop()` writes the `default` variant only, so a responsive crop had to be
  hand-written JSON.

- **@plan2net/typo3-playwright-toolkit** — `RelationOwner`, `RelationOutput` and
  `ChildRecord` are exported. `getRelations` is part of `ContentBuilderInterface`, but
  its types were not public, so a content type whose crop or metadata setter runs
  after the one attaching the file could not write its relation at the end and had to
  fall back to `getAdditionalRecords` and its own `NEW` identifiers.

## [0.7.0] - 2026-08-28

### Breaking

- **@plan2net/typo3-playwright-toolkit** — the `saveRecord` fixture returns
  `{ uid, slug }` instead of the uid on its own. A setup that used the return value
  directly reads `.uid` now. The builders are unchanged.

### Added

- **both packages** — `builders.page().create()` reports the slug the site stored,
  not the one it was asked for. The site does not always keep what you post — a
  translation and a name already in use are two cases, and an extension can add more
  — so a test that navigated to the string it passed in landed on a 404. The save
  answers with `X-Playwright-Saved-Record`, a JSON header on the redirect it already
  sends, so this costs no second request. The value is escaped JSON, which keeps a
  slug with an umlaut ASCII in the header, and the header needs the API secret like
  every other entry point.

- **both packages** — the add-on says which release it is, in
  `.ddev/playwright-toolkit.version`, and `playwright:prepare` warns when that no
  longer matches the installed extension. DDEV records no version for an add-on
  installed from a release tarball, and the files it copies into `.ddev` never update
  themselves, so an add-on left behind by a `composer update` was invisible. A
  development checkout of the extension says nothing, since the two can never match
  there.

- **plan2net/playwright-toolkit** — `playwright:prepare` warns when no fixtures are
  configured. Both fixture settings default to empty, which builds a template with
  the schema and a backend session and no content at all, so every test that opens a
  page got a 404 and no hint why. There is no sensible default to ship — the root
  page id and the site belong to your project — so the command names the two settings
  instead.

- **ddev-typo3-playwright-toolkit** — `ddev playwright` says what to fix when the
  Testing context reaches a database with no TYPO3 tables. The cache flush is the
  first step that touches it, and it used to fail with a Doctrine trace naming no
  cause. The command now names the two ways out: build the schema there, or point the
  Testing context at the database your project already uses.

### Fixed

- **@plan2net/typo3-playwright-toolkit** — `expectScreenshot` no longer passes the
  tolerance on every call, where it beat a `threshold` set through Playwright's own
  `expect.toHaveScreenshot`. Both read the same default, so they only disagreed for a
  project that set it the Playwright way — and then only `expectScreenshot` ignored
  it.

- **ddev-typo3-playwright-toolkit** — `ddev playwright-replay` names the `db-test`
  service in its messages. "Rebuilds the testing site database" read as if it drops
  the database you work in.

### Documentation

- `SETUP.md` is the one place the setup lives, and every README and the landing page
  link to it. It was spread over four READMEs before, and the three parts that cost a
  first-time consumer the most were the hardest to find: the testing host name, never
  explained as "your site on a second host name"; the root page fixture, never
  mentioned at all, without which the documented example returns a 404; and the
  `additional.php` ordering, which lived in the extension README only. Get that last
  one wrong and your tests pass against your real database. The browser install now
  comes after the setting that decides where the browsers go, not before it.

- **@plan2net/typo3-playwright-toolkit** — the README says that screenshots are
  stored in CSS pixels, which is Playwright's default. A project whose viewport
  projects set a `deviceScaleFactor` therefore compares fewer pixels than its browser
  drew: a bug that only shows at 2x cannot fail a test, and images taken with
  `element.screenshot()` never match. `scale: 'device'` was always accepted; nothing
  said so.

## [0.6.0] - 2026-08-28

### Breaking

- **@plan2net/typo3-playwright-toolkit** — the setters for columns core stores as a
  number now take a name instead: `withBulletsType('numbers')`,
  `withHeaderPosition('top')` and `withOrientation('in-text-right')` no longer accept
  `1`, `1` and `17`. `withHeader` takes the level as a second argument
  (`withHeader('Chapter', 'h3')`), and `PageBuilder` gains `withDoktype('folder')`.
  The numbers say nothing on their own — `header_layout` 100 means hidden — and a
  name is something your editor can suggest and check. `withDoktype` also takes a
  number, for a doktype your own project registered; for every other column,
  `withField` is the way out.

### Added

- **@plan2net/typo3-playwright-toolkit** — four setters attach a relation:
  `withFileReference`, `withFileReferences`, `withChild` and `withChildren`. They work
  the same on a content type, on a child record and on the builder in a test, so a
  child carries its own files and children to any depth and no builder mints a `NEW`
  identifier by hand. Call order decides the order; `pid` and `sys_language_uid` come
  from the record above. The columns the toolkit writes itself are refused, as is a
  column filled from two places — a `uid_foreign` of your own saves a row pointing at
  nothing without failing.

- **@plan2net/typo3-playwright-toolkit** — `imageCrop()` writes the JSON text the
  `crop` column of a file reference holds: `imageCrop({ ratio: '16:9' })` keeps the
  whole image at that ratio, and an `area` crops a part of it. It also returns a
  string, so it cannot be mistaken for a value the request body should nest.

### Fixed

- **@plan2net/typo3-playwright-toolkit** — a builder whose extra records are `tt_content`
  rows — a container element with content children — keeps its own element. The records
  were spread over the datamap's root table, so the children replaced the element that
  was being created. Two records claiming one identifier now fail instead of merging
  into each other.

- **@plan2net/typo3-playwright-toolkit** — content elements now appear in the order the
  scenario created them. Every element was posted with the page id, which puts it at the
  top, so pages came out reversed — and silently, which left the first screenshot run
  recording that as its baseline. A scenario that compensated by building backwards
  needs turning around.

- **plan2net/playwright-toolkit** — a test database whose seeded session cannot be
  read is left alone instead of rebuilt. The session id is hashed with
  `SYS/encryptionKey`, so the wrong key makes the lookup miss, and every request then
  dropped and re-cloned its own database — throwing away what the test had just
  built, mid-run and silently. Such a database now fails with what to fix. One
  holding no session at all is still rebuilt.

### Documentation

- **plan2net/playwright-toolkit** — the README and `CONTRACT.md` now say that
  `SYS/encryptionKey` has to be in `$GLOBALS['TYPO3_CONF_VARS']` before
  `applyDatabaseConnectionOverrides()` runs. The seeded session id is hashed with it
  to tell an already-seeded database from a new one, pre-boot, so a key a project
  applies later makes every request re-clone its database and lose what the test
  built.

- The landing page example attaches its files with `withFileReferences` and
  `imageCrop`, so the page shows how a relation is written.

- The rotating claim in the landing page headline is readable: it carried the brand
  orange on white at 2.41:1, where large text needs 3:1. It now uses the TYPO3
  GmbH's text orange, `#ED6D05`, which is the second orange their own palette keeps
  for exactly this. `#FF8700` stays the identity colour and still fills the buttons.

- The landing page serves its three fonts from its own origin instead of linking
  Google Fonts, so opening it sends no visitor's IP address to a third party. The
  build fails if any subresource points off-site again.

## [0.5.0] - 2026-08-28

### Breaking

- **@plan2net/typo3-playwright-toolkit** — `defineBasePlaywrightConfig` refuses
  overrides of `globalSetup`, `globalTeardown`, `use.baseURL` and
  `use.serviceWorkers`. The hooks carry the preflight, the run bookkeeping and the
  database cleanup; the other two keep the toolkit headers on one origin.

- **@plan2net/typo3-playwright-toolkit** — a nested value passed to `withField` is
  posted as one field per value, not as JSON. `CropConfig` left the field types with
  it: a crop belongs to `sys_file_reference`, not to a `pages` or `tt_content` column.

- **@plan2net/typo3-playwright-toolkit** — a second `defineScenario` in one test file
  now fails. A scenario is named after its file, so both shared one test database and
  only the first setup ran.

### Added

- **@plan2net/typo3-playwright-toolkit** — flexform columns can be written:
  `withField('pi_flexform', flexForm({ 'settings.limit': 10 }))`. The form posts one
  input per value, not one for the column, so such a value used to land in the column
  as JSON. Plain values go to `sDEF`; name a sheet per group where the structure has
  several. The input name is the same in 11.5, 12.4, 13.4 and 14.3.

### Changed

- **plan2net/playwright-toolkit** — the per-test database is created *before* TYPO3
  boots, in the same call that points the connection at it, instead of in a
  `BootCompletedEvent` listener. Another extension's listener could run first and
  query a database that did not exist yet, and listener order between extensions is
  undefined, so whether it worked was luck. The template check needs a booted TYPO3
  and stays behind, in the new `TemplateDriftGuard`, once per database.

- **plan2net/playwright-toolkit** — `playwright:prepare` skips the rebuild when the
  stored fingerprint matches the current schema, fixtures and session seed, so an
  unchanged template no longer costs 30–60s per run. `--force` rebuilds anyway.

- **plan2net/playwright-toolkit** — locking uses `symfony/lock` instead of `flock()`
  in five places. `LockFiles` offers `shared()`, `exclusively()` and
  `exclusivelyWithin()`, so no caller opens a file handle.

### Fixed

- **plan2net/playwright-toolkit** — the Default connection is no longer pointed at a
  test database nothing created, which failed the request during boot with
  `Unknown database`. It happened wherever the paths are merged by hand through
  `databaseConnectionOverrides()`, since only `applyDatabaseConnectionOverrides()`
  created the database, and for every request with a well-formed test ID and no
  secret. Both calls now decide the same way.

- **DDEV add-on** — `ddev playwright-prepare` refuses with "run `ddev restart`" while
  the db-test service is configured but not active yet. Running it before that
  restart failed with a bare connection error — or worse, on a host with several
  toolkit projects `db-test` resolved to another project's service and prepare wrote
  into its template.

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

[Unreleased]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.9.0...main
[0.9.0]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.4.2...v0.5.0
[0.4.2]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.4.1...v0.4.2
[0.4.1]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/plan2net/typo3-playwright-toolkit/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/plan2net/typo3-playwright-toolkit/releases/tag/v0.1.0
