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
| Saved record header | `X-Playwright-Saved-Record` | npm `SAVED_RECORD_HEADER`, PHP `SavedRecord::HEADER` |
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

**Never assume the `db` database has test data.** Only a per-test database has it —
and the replay run below, which is the one exception and says so.

## The project's own file

TYPO3 auto-loads one additional-configuration file and no context-suffixed variant, so
the project puts the call there, behind the context check. Under Composer on 12.4,
13.4 and 14.3 that file is `config/system/additional.php`; the extension README lists
the two older layouts.

```php
if (\TYPO3\CMS\Core\Core\Environment::getContext()->isTesting()) {
    \Plan2net\PlaywrightToolkit\TestContext::configureCurrentRequest();
}
```

**The check must stay.** `configureCurrentRequest()` acts on the test ID
alone; called outside the Testing context it would let a request carrying that header
switch the connection on an ordinary hostname.

If a project needs its own merge, `resolveTestDatabaseConnection($defaultConnection)`
returns the values instead. It creates the test database too, so either entry point
leaves the connection naming a database that exists — or naming nothing at all.

**`SYS/encryptionKey` must be in `$GLOBALS['TYPO3_CONF_VARS']` before either call.**
Both hash the pre-seeded session id with it to tell an already-seeded database from a
new one, pre-boot. A key that a project applies afterwards arrives too late, and every
request then re-clones its database and discards what the test built.

Those values are paths like `DB/Connections/Default/dbname`,
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
session) and redirects to the backend. Both cookies are `HttpOnly`, `Secure` and
`SameSite=Lax`, and both end when the browser closes.

**Read the header first and the cookie only if there is no header.** Otherwise a
cookie left in a browser changes what a test run does.

A cookie can select a database but never create one, because creating one needs the
secret and a browser never sends it.

## Replay: one test ID, one database

`ddev playwright-replay` runs every scenario's *setup* into a single database, so all
the content the suite builds can be browsed and exported in one place. It carries an
ordinary test ID on the wire — the fixed `REPLAY0000000000` (`DatabaseName::REPLAY_TEST_ID`,
`REPLAY_TEST_ID` in `src/contract.ts`) — so every step above works unchanged: the
connection is redirected to the test service, the session endpoint answers, and the
inspect link is an ordinary `?id=…` one.

**That one ID maps to the bare database name `db`, not `dbREPLAY0000000000`.** It is
the throwaway database on the db-test container, and it is the only test ID that
reaches a bare name — `forTestIdChecked()` allows it by name, so an empty or
malformed ID still throws.

The guards then split, deliberately:

- `assertProvisionable('db')` passes, so `DatabaseInitializer` provisions it on the
  first secret-carrying request of a run and `isAlreadySeeded()` reuses it for the
  rest, which is how the content accumulates.
- `isDroppable('db')` **fails**, so nothing reachable from the wire can drop it —
  neither cleanup route, nor the sweep. `typo3 playwright:replay-prepare` is the only
  thing that rebuilds it, and it is CLI-only and Testing-context-only.

Nothing else on the wire changes shape, so there is no replay fixture to pin.

## Writing records

There is no toolkit endpoint for creating content. The builders post to
`record/edit`, the same route the TYPO3 backend form uses. Keep it that way:
if TYPO3 changes that route or its fields, the tests must fail rather than pass on
an endpoint of our own.

The save answers with a redirect naming the new uid, and `SavedRecordHeader` adds
`X-Playwright-Saved-Record` to it — JSON, today `{"slug": "…"}`. The site does not
always keep the slug that was posted, so this is the only way the caller learns the
page's URL without a second request. The middleware only reads; it takes the secret like
every other entry point and, without it, hands back the backend's own answer
untouched. A new field belongs in that JSON, not in a header of its own.

`POST /typo3/test-api/session` returns the session cookie and the tokens:

```json
{
    "cookieName": "be_typo_user",
    "cookieValue": "<cookie>",
    "backendPath": "/typo3",
    "tokens": { "record_edit": "…" }
}
```

**The names are reported, never assumed.** `BE/cookieName` and `BE/entryPoint` are
both a project's to change, so the extension reads them and the toolkit uses what
it is told — `cookieName` for the cookie it sets, `backendPath` for the route it
posts to. `BE/entryPoint` exists only on TYPO3 13.4 and 14.3; on 11.5 and 12.4 the
answer is always `/typo3`.

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

A test ID is `sha256(runSalt:scenarioKey:attempt)`. `runSalt` is 16 random bytes, made
once per run.

**Never derive a test ID from the run ID.** A run ID can be set by hand and guessed.
A test ID is enough to reach that test's database, so it must not be guessable.

## Version check

The health response contains `"api"`. The toolkit reads it before it creates any
test ID, using a request with no headers, and stops if the extension is too old.

**Keep that order.** If a test ID were created first, it would create a database
that an old extension has no endpoint to delete.
