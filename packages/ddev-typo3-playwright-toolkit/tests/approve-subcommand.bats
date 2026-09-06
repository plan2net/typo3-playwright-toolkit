#!/usr/bin/env bats

ADDON_DIR="$(cd "$(dirname "${BATS_TEST_FILENAME}")/.." && pwd)"

setup() {
    TEST_DIR="${BATS_TEST_TMPDIR}/tests/playwright"
    mkdir -p "${TEST_DIR}/test-results"
    cd "${TEST_DIR}" || exit 1

    # shellcheck source=../playwright-lib.sh
    . "${ADDON_DIR}/playwright-lib.sh"
}

@test "help documents the approve subcommand" {
    run "${ADDON_DIR}/commands/web/playwright" --help
    [ "$status" -eq 0 ]
    [[ "$output" == *"approve"* ]]
}

@test "approve targets a specific test path" {
    playwright_resolve_approve_args accordion

    [ "${PW_APPROVE_ARGS[0]}" = "test" ]
    [ "${PW_APPROVE_ARGS[1]}" = "accordion" ]
    [ "${PW_APPROVE_ARGS[2]}" = "--update-snapshots" ]
}

@test "approve targets a specific file with path and extension" {
    playwright_resolve_approve_args tests/playwright/accordion.spec.ts

    [ "${PW_APPROVE_ARGS[0]}" = "test" ]
    [ "${PW_APPROVE_ARGS[1]}" = "tests/playwright/accordion.spec.ts" ]
    [ "${PW_APPROVE_ARGS[2]}" = "--update-snapshots" ]
}

@test "approve keeps quoted grep patterns intact" {
    playwright_resolve_approve_args --grep "three facts and a lie"

    [ "${PW_APPROVE_ARGS[0]}" = "test" ]
    [ "${PW_APPROVE_ARGS[1]}" = "--grep" ]
    [ "${PW_APPROVE_ARGS[2]}" = "three facts and a lie" ]
    [ "${PW_APPROVE_ARGS[3]}" = "--update-snapshots" ]
}

@test "approve uses --last-failed when test-results/.last-run.json recorded failures" {
    cat > "${TEST_DIR}/test-results/.last-run.json" <<'JSON'
{
  "status": "failed",
  "failedTests": ["accordion.spec.ts:10:5"]
}
JSON

    playwright_resolve_approve_args

    [ "${PW_APPROVE_ARGS[0]}" = "test" ]
    [ "${PW_APPROVE_ARGS[1]}" = "--last-failed" ]
    [ "${PW_APPROVE_ARGS[2]}" = "--update-snapshots" ]
}

@test "approve uses --last-failed when root .last-run.json recorded failures" {
    cat > "${TEST_DIR}/.last-run.json" <<'JSON'
{
  "status": "failed",
  "failedTests": ["teaser.spec.ts:15:3"]
}
JSON

    playwright_resolve_approve_args

    [ "${PW_APPROVE_ARGS[0]}" = "test" ]
    [ "${PW_APPROVE_ARGS[1]}" = "--last-failed" ]
    [ "${PW_APPROVE_ARGS[2]}" = "--update-snapshots" ]
}

@test "approve keeps failed-test selection when .last-run.json does not exist" {
    playwright_resolve_approve_args

    [ "${PW_APPROVE_ARGS[0]}" = "test" ]
    [ "${PW_APPROVE_ARGS[1]}" = "--last-failed" ]
    [ "${PW_APPROVE_ARGS[2]}" = "--update-snapshots" ]
    [ "${#PW_APPROVE_ARGS[@]}" -eq 3 ]
}

@test "approve keeps failed-test selection when .last-run.json recorded passed" {
    cat > "${TEST_DIR}/test-results/.last-run.json" <<'JSON'
{
  "status": "passed",
  "failedTests": []
}
JSON

    playwright_resolve_approve_args

    [ "${PW_APPROVE_ARGS[0]}" = "test" ]
    [ "${PW_APPROVE_ARGS[1]}" = "--last-failed" ]
    [ "${PW_APPROVE_ARGS[2]}" = "--update-snapshots" ]
    [ "${#PW_APPROVE_ARGS[@]}" -eq 3 ]
}

@test "approve --all bypasses --last-failed even when failures were recorded" {
    cat > "${TEST_DIR}/test-results/.last-run.json" <<'JSON'
{
  "status": "failed",
  "failedTests": ["accordion.spec.ts:10:5"]
}
JSON

    playwright_resolve_approve_args --all

    [ "${PW_APPROVE_ARGS[0]}" = "test" ]
    [ "${PW_APPROVE_ARGS[1]}" = "--update-snapshots" ]
    [ "${#PW_APPROVE_ARGS[@]}" -eq 2 ]
}

@test "approve forwards options like --project alongside the pattern" {
    playwright_resolve_approve_args accordion --project=chromium

    [ "${PW_APPROVE_ARGS[0]}" = "test" ]
    [ "${PW_APPROVE_ARGS[1]}" = "accordion" ]
    [ "${PW_APPROVE_ARGS[2]}" = "--project=chromium" ]
    [ "${PW_APPROVE_ARGS[3]}" = "--update-snapshots" ]
}

@test "approve does not duplicate --update-snapshots if already supplied" {
    playwright_resolve_approve_args accordion --update-snapshots

    count=0
    for arg in "${PW_APPROVE_ARGS[@]}"; do
        if [ "${arg}" = "--update-snapshots" ]; then
            count=$((count + 1))
        fi
    done

    [ "${count}" -eq 1 ]
}

@test "approve does not duplicate shorthand -u if already supplied" {
    playwright_resolve_approve_args accordion -u

    count=0
    for arg in "${PW_APPROVE_ARGS[@]}"; do
        if [ "${arg}" = "-u" ] || [ "${arg}" = "--update-snapshots" ]; then
            count=$((count + 1))
        fi
    done

    [ "${count}" -eq 1 ]
}
