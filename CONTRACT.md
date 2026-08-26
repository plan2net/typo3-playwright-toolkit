# Wire contract

The three packages only work together if they agree on the values and rules below.
Each value has one owner. No other place may define it again.

Read this before you change anything that touches a test ID.

## Values

| What | Value | Defined in |
|---|---|---|
| Test ID header | `X-Playwright-Test-Id` | npm `TEST_ID_HEADER`, PHP `TestContext::TEST_ID_HEADER` |
| Test ID format | `^[A-Z0-9]{16}$` | npm `TEST_ID_PATTERN`, PHP `TestContext::TEST_ID_PATTERN` |
| Secret header | `X-Playwright-Toolkit-Secret` | npm `SECRET_HEADER`, PHP `TestApiSecret::HEADER` |
| Secret file | `var/playwright/api-secret` | PHP writes it, npm reads it |
| Secret override | `PLAYWRIGHT_TOOLKIT_SECRET` | environment, read by both |
| Server key | `HTTP_X_PLAYWRIGHT_TEST_ID` | PHP `TestContext::TEST_ID_SERVER_KEY` |
| Database prefix | `db` | PHP `TestContext::DATABASE_PREFIX` |

The database for a test is `db` plus its test ID.

## Rules

**Send the test ID as a header.** The toolkit puts it on every request to the
testing host. Apache and nginx pass it to PHP on their own. Do not add webserver
configuration for it, and do not let the DDEV add-on write any.

**Send the secret to every `/typo3/test-api/*` endpoint.** Without it the endpoint
answers `401` and nothing else. The test ID alone selects a database; only the
secret allows creating or dropping one.

**Treat a missing or invalid test ID as "not our request".** The site keeps its own
database and nothing is created. Never answer such a request with an error: anyone
can send a junk header, and the toolkit did not send it.

**Never build a database name from an unchecked value.** The name goes into
`CREATE DATABASE` and `DROP DATABASE`. `DatabaseName::assertProvisionable()` is the
gate and must stay in front of both.

**Never assume the `db` database has test data.** Only a per-test database has it.

## The project's own file

`config/system/additional-testing.php` belongs to the project and contains:

```php
Plan2net\PlaywrightToolkit\TestContext::applyDatabaseConnectionOverrides();
```

If a project needs its own merge, `databaseConnectionOverrides($defaultConnection)`
returns the values instead. Those are paths like `DB/Connections/Default/dbname`,
not keys. Merging them as keys creates a setting with a slash in its name, and every
test then runs against the project's own database.

## Opening a kept database in the browser

A browser cannot send the header. So the toolkit prints a signed link, and the
extension answers it at `GET /typo3/test-api/inspect`:

```
?id=<testId>&t=<expiresAt>.<hmac>

hmac = HMAC-SHA256(secret, "inspect:" + testId + ":" + expiresAt), as hex
```

The link is valid for 900 seconds. The secret is never in the URL.
`contract/inspect-token.json` holds one example token. Both packages must produce
exactly that token for those inputs.

The endpoint checks the signature, sets two cookies (the test ID and the backend
session) and redirects to `/typo3/`. Both cookies are `HttpOnly`, `Secure` and
`SameSite=Lax`, and both end when the browser closes.

**Read the header first and the cookie only if there is no header.** Otherwise a
cookie left in a browser changes what a test run does.

A cookie can select a database but never create one, because creating one needs the
secret and a browser never sends it.

## Writing records

There is no toolkit endpoint for creating content. The builders post to
`/typo3/record/edit`, the same route the TYPO3 backend form uses. Keep it that way:
if TYPO3 changes that route or its fields, the tests must fail rather than pass on
an endpoint of our own.

`POST /typo3/test-api/session` returns the session cookie and the tokens:

```json
{ "cookieName": "be_typo_user", "cookieValue": "<cookie>", "tokens": { "record_edit": "…" } }
```

The request may send a readable name for the test:

```json
{ "name": "accordion-simple" }
```

The extension keeps it next to the database and shows it in the backend, as
`My Project [accordion-simple · ABCD1234EFGH5678]`.

When you post a record:

- put the request token in `&token=`
- put the fields in `data[<table>][<identifier>][<column>]`
- add `doSave=1` and `_savedok=1`
- **do not follow the redirect.** The new uid is only in the `Location` header.
- if the answer is a page instead of a redirect, the save failed. Raise an error;
  never return a made-up uid.

**Never add a way to skip the request token.** The tests exist to exercise the real
one.

## Cleanup

The toolkit never uses SQL. It sends test IDs, and the extension deletes the
databases, because only the extension knows the engine and the credentials. This is
what allows PHP and Node to run in different containers.

```
POST /typo3/test-api/databases/drop   { "testIds": [...] }
POST /typo3/test-api/databases/sweep  { "keepTestIds": [...], "minimumAgeMs": N }
```

**Send the test IDs in the body, never as a header.** A cleanup request with a test
ID header creates that database on the way in, and the endpoint then deletes what it
just created.

Every database comes back with one result:

| Result | Meaning |
|---|---|
| `dropped` | it existed and is gone |
| `absent` | there was nothing to delete |
| `unclaimed` | a database exists that this extension never created |
| `refused` | the ID has the wrong format |
| `failed` | it could not be deleted |

Only `failed` is kept in the run records, so the problem stays visible. `absent`
makes a repeated request safe: if an answer is lost, asking again returns `absent`
instead of an error.

## Who owns what

The two packages share no files.

- The **extension** owns `var/test-locks`. It writes `db-<name>.lock` before it
  creates a database, keeps the file even if that fails, and deletes it only after
  the database is gone. The toolkit must never read or write there.
- The **toolkit** owns the run records under `stateDir/runs/<runId>/`. It writes
  every database it asks for into `attempts.jsonl` before the request, and later
  asks for exactly those to be dropped.

So only the extension knows which databases exist, and only the toolkit knows which
runs are still running. That is why `sweep` needs `keepTestIds`: it is the toolkit
telling the extension what is still in use.

**Only pass the test IDs of running runs as `keepTestIds`.** Anything else is
protected forever and never cleaned up.

## Run IDs and test IDs

`PW_RUN_ID` separates the records of one run from another. Two runs must not share
one: `prepareRun` refuses a run ID that another running process already uses.

A test ID is `sha256(runSalt:pairKey:attempt)`. `runSalt` is 16 random bytes, made
once per run.

**Never derive a test ID from the run ID.** A run ID can be set by hand and guessed.
A test ID is enough to reach that test's database, so it must not be guessable.

## Version check

The health response contains `"api"`. The toolkit reads it before it creates any
test ID, using a request with no headers, and stops if the extension is too old.

**Keep that order.** If a test ID were created first, it would create a database
that an old extension has no endpoint to delete.
