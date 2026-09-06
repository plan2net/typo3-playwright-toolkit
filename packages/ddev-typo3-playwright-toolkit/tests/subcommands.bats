#!/usr/bin/env bats

ADDON_DIR="$(cd "$(dirname "${BATS_TEST_FILENAME}")/.." && pwd)"

setup() {
    export PW_ADDON_CONFIG_DIR="${ADDON_DIR}"
    export PW_TEST_DIR="${BATS_TEST_TMPDIR}/project/tests/playwright"
    export PW_SKIP_PREPARE=1
    export DDEV_PRIMARY_URL="https://example.ddev.site"
    export TRACE_CALLS="${BATS_TEST_TMPDIR}/calls"
    export DDEV_APPROOT="${BATS_TEST_TMPDIR}/project"
    export APPROVE_PREPARE_CALLS="${BATS_TEST_TMPDIR}/prepare-calls"
    mkdir -p "${PW_TEST_DIR}" "${BATS_TEST_TMPDIR}/bin" "${DDEV_APPROOT}/vendor/bin"
    cp "${ADDON_DIR}/tests/fixtures/approve-typo3.sh" "${DDEV_APPROOT}/vendor/bin/typo3"
    chmod +x "${DDEV_APPROOT}/vendor/bin/typo3"
    cp "${ADDON_DIR}/tests/fixtures/trace-npx.sh" "${BATS_TEST_TMPDIR}/bin/npx"
    chmod +x "${BATS_TEST_TMPDIR}/bin/npx"
    export PATH="${BATS_TEST_TMPDIR}/bin:${PATH}"
    COMMAND="${ADDON_DIR}/commands/web/playwright"
    cd "${BATS_TEST_TMPDIR}/project" || exit 1
}

@test "ui runs Playwright UI mode and preserves a quoted filter" {
    run "${COMMAND}" ui --grep 'two words; echo ignored'

    [ "$status" -eq 0 ]
    run cat "${TRACE_CALLS}"
    [ "$output" = $'playwright\ntest\n--ui-port=3000\n--ui-host=0.0.0.0\n--grep\ntwo words; echo ignored' ]
}

@test "inspect forwards the filter to the toolkit's inspect command" {
    run "${COMMAND}" inspect 'two words'

    [ "$status" -eq 0 ]
    run cat "${TRACE_CALLS}"
    [ "$output" = $'typo3-playwright-inspect\ntwo words' ]
}

@test "doctor forwards project selection without preparing the database" {
    unset PW_SKIP_PREPARE
    run "${COMMAND}" doctor --project 'mobile chrome'

    [ "$status" -eq 0 ]
    [ ! -e "${APPROVE_PREPARE_CALLS}" ]
    run cat "${TRACE_CALLS}"
    [ "$output" = $'typo3-playwright-doctor\n--project\nmobile chrome' ]
}

@test "clean calls the toolkit's cleanup command without preparing the database" {
    unset PW_SKIP_PREPARE
    run "${COMMAND}" clean

    [ "$status" -eq 0 ]
    [ ! -e "${APPROVE_PREPARE_CALLS}" ]
    run cat "${TRACE_CALLS}"
    [ "$output" = "typo3-playwright-clean" ]
}

@test "playwright-ui remains an alias for UI mode" {
    run "${ADDON_DIR}/commands/web/playwright-ui" --grep 'two words'

    [ "$status" -eq 0 ]
    run cat "${TRACE_CALLS}"
    [ "$output" = $'playwright\ntest\n--ui-port=3000\n--ui-host=0.0.0.0\n--grep\ntwo words' ]
}

@test "replay prepares its database and runs the filtered scenarios with one worker" {
    run "${COMMAND}" replay --grep 'two words'

    [ "$status" -eq 0 ]
    run cat "${TRACE_CALLS}"
    [ "$output" = $'playwright\ntest\n--workers=1\n--grep\ntwo words' ]
    run cat "${APPROVE_PREPARE_CALLS}"
    [ "$output" = 'Testing playwright:replay-prepare' ]
}

@test "prepare forwards force and runs even when automatic preparation is skipped" {
    run "${COMMAND}" prepare --force

    [ "$status" -eq 0 ]
    [ ! -e "${TRACE_CALLS}" ]
    run cat "${APPROVE_PREPARE_CALLS}"
    [ "$output" = $'Testing cache:flush\nTesting playwright:prepare --force' ]
}

@test "subcommand help uses the spaced names without requiring a project" {
    export PW_TEST_DIR="${BATS_TEST_TMPDIR}/missing"
    export DDEV_APPROOT="${BATS_TEST_TMPDIR}/missing"
    for subcommand in ui replay inspect prepare doctor clean; do
        run "${COMMAND}" "${subcommand}" --help
        [ "$status" -eq 0 ]
        [[ "$output" == *"Usage: ddev playwright ${subcommand}"* ]] || return 1
    done
}
