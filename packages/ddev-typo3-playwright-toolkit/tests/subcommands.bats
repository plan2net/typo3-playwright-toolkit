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

@test "a test run points at the report command that works from the host" {
    mkdir -p "${PW_TEST_DIR}/playwright-report"

    run "${COMMAND}" test

    [ "$status" -eq 0 ]
    [[ "$output" == *"ddev playwright show-report"* ]] || return 1
}

@test "a test run gets no terminal on stdin, so Playwright prints no npx hint" {
    cp "${ADDON_DIR}/tests/fixtures/stdin-npx.sh" "${BATS_TEST_TMPDIR}/bin/npx"
    chmod +x "${BATS_TEST_TMPDIR}/bin/npx"

    run script -q -c "${COMMAND} test" /dev/null

    [ "$status" -eq 0 ]
    run cat "${TRACE_CALLS}"
    [ "$output" = "not a terminal" ]
}

@test "a failing test run keeps its exit status, hint or no hint" {
    mkdir -p "${PW_TEST_DIR}/playwright-report"
    export TRACE_EXIT=3

    run "${COMMAND}" test

    [ "$status" -eq 3 ]
}

@test "UI mode names a URL a browser can open, not the bind address" {
    cp "${ADDON_DIR}/tests/fixtures/serve-npx.sh" "${BATS_TEST_TMPDIR}/bin/npx"
    chmod +x "${BATS_TEST_TMPDIR}/bin/npx"

    run "${ADDON_DIR}/commands/web/playwright-ui"

    [ "$status" -eq 0 ]
    [[ "$output" == *"https://example.ddev.site:3000"* ]] || return 1
    [[ "$output" != *"0.0.0.0"* ]] || return 1
}

@test "show-report says how to produce a report when there is none" {
    cp "${ADDON_DIR}/tests/fixtures/no-report-npx.sh" "${BATS_TEST_TMPDIR}/bin/npx"
    chmod +x "${BATS_TEST_TMPDIR}/bin/npx"

    run "${COMMAND}" show-report

    [ "$status" -eq 1 ]
    [[ "$output" == *"--reporter=html"* ]] || return 1
}

@test "show-report blames the reporter only when the report is the thing missing" {
    mkdir -p "${PW_TEST_DIR}/playwright-report"
    cp "${ADDON_DIR}/tests/fixtures/no-report-npx.sh" "${BATS_TEST_TMPDIR}/bin/npx"
    chmod +x "${BATS_TEST_TMPDIR}/bin/npx"

    run "${COMMAND}" show-report

    [ "$status" -eq 1 ]
    [[ "$output" != *"--reporter=html"* ]] || return 1
}

@test "show-report names the port and its variable when something else holds it" {
    node -e 'require("net").createServer().listen(9323, "0.0.0.0")' &
    listener=$!
    sleep 1

    run "${COMMAND}" show-report
    kill "${listener}" 2>/dev/null

    [ "$status" -eq 1 ]
    [[ "$output" == *"9323"* ]] || return 1
    [[ "$output" == *"PW_REPORT_PORT"* ]] || return 1
}

@test "the served report names a URL a browser can open, not the bind address" {
    cp "${ADDON_DIR}/tests/fixtures/serve-npx.sh" "${BATS_TEST_TMPDIR}/bin/npx"
    chmod +x "${BATS_TEST_TMPDIR}/bin/npx"

    run "${COMMAND}" show-report

    [ "$status" -eq 0 ]
    [[ "$output" == *"https://example.ddev.site:9323"* ]] || return 1
    [[ "$output" != *"0.0.0.0:9323"* ]] || return 1
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
    [ "$output" = $'Testing cache:flush --group system\nTesting playwright:prepare --force' ]
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
