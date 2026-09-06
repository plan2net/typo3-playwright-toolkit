#!/usr/bin/env bats

ADDON_DIR="$(cd "$(dirname "${BATS_TEST_FILENAME}")/.." && pwd)"

setup() {
    export PW_ADDON_CONFIG_DIR="${ADDON_DIR}"
    export PW_TEST_DIR="${BATS_TEST_TMPDIR}/project/tests/playwright"
    export PW_SKIP_PREPARE=1
    export APPROVE_CALLS="${BATS_TEST_TMPDIR}/calls"
    unset PW_SKIP_BUILD NO_DATABASE_CLEANUP APPROVE_EXIT APPROVE_PREPARE_CALLS APPROVE_PREPARE_EXIT
    mkdir -p "${PW_TEST_DIR}/test-results" "${BATS_TEST_TMPDIR}/bin"
    printf '%s' '{"status":"failed","failedTests":["test-id"]}' > "${PW_TEST_DIR}/test-results/.last-run.json"
    cp "${ADDON_DIR}/tests/fixtures/approve-npx.sh" "${BATS_TEST_TMPDIR}/bin/npx"
    chmod +x "${BATS_TEST_TMPDIR}/bin/npx"
    export PATH="${BATS_TEST_TMPDIR}/bin:${PATH}"
    COMMAND="${ADDON_DIR}/commands/web/playwright"
    cd "${BATS_TEST_TMPDIR}/project" || exit 1
}

@test "approve runs the selected file from the configured test directory" {
    run "${COMMAND}" approve accordion

    [ "$status" -eq 0 ]
    run cat "${APPROVE_CALLS}"
    [ "${output}" = $'playwright\ntest\naccordion\n--update-snapshots' ]
}

@test "approve without a filter never falls back to the whole suite" {
    run "${COMMAND}" approve

    [ "$status" -eq 0 ]
    run cat "${APPROVE_CALLS}"
    [ "${output}" = $'playwright\ntest\n--last-failed\n--update-snapshots' ]
}

@test "separate option values do not count as approval targets" {
    run "${COMMAND}" approve --project chromium --config "local config.ts" --output "custom results" --workers 2

    [ "$status" -eq 0 ]
    run cat "${APPROVE_CALLS}"
    [ "${output}" = $'playwright\ntest\n--last-failed\n--project\nchromium\n--config\nlocal config.ts\n--output\ncustom results\n--workers\n2\n--update-snapshots' ]
}

@test "runner options and exclusion filters keep failed-test selection" {
    run "${COMMAND}" approve --grep-invert "slow tests" --reporter line --retries 1 --timeout 1000 --trace retain-on-failure

    [ "$status" -eq 0 ]
    run cat "${APPROVE_CALLS}"
    [ "${lines[2]}" = '--last-failed' ]
}

@test "multiple projects do not turn approval into a full project run" {
    run "${COMMAND}" approve --project chromium firefox

    [ "$status" -eq 0 ]
    run cat "${APPROVE_CALLS}"
    [ "${output}" = $'playwright\ntest\n--last-failed\n--project\nchromium\nfirefox\n--update-snapshots' ]
}

@test "unknown options cannot turn their values into file filters" {
    run "${COMMAND}" approve --future-option something

    [ "$status" -ne 0 ]
    [[ "$output" == *'Unsupported approve option: --future-option'* ]] || return 1
    [ ! -e "${APPROVE_CALLS}" ]
}

@test "approve help works without a test directory or database" {
    export PW_TEST_DIR="${BATS_TEST_TMPDIR}/missing"
    unset PW_SKIP_PREPARE
    run "${COMMAND}" approve --help

    [ "$status" -eq 0 ]
    [[ "$output" == *'--all'* ]] || return 1
    [[ "$output" == *'never falls back'* ]] || return 1
    [ ! -e "${APPROVE_CALLS}" ]
}

@test "approval preserves Playwright's exit status" {
    export APPROVE_EXIT=7

    run "${COMMAND}" approve accordion

    [ "$status" -eq 7 ]
}

@test "approval prepares the database before running Playwright" {
    unset PW_SKIP_PREPARE
    export DDEV_APPROOT="${BATS_TEST_TMPDIR}/project"
    export APPROVE_PREPARE_CALLS="${BATS_TEST_TMPDIR}/prepare-calls"
    mkdir -p "${DDEV_APPROOT}/vendor/bin"
    cp "${ADDON_DIR}/tests/fixtures/approve-typo3.sh" "${DDEV_APPROOT}/vendor/bin/typo3"
    chmod +x "${DDEV_APPROOT}/vendor/bin/typo3"

    run "${COMMAND}" approve accordion

    [ "$status" -eq 0 ]
    run cat "${APPROVE_PREPARE_CALLS}"
    [ "$output" = $'Testing cache:flush\nTesting playwright:prepare\nplaywright' ]
}
