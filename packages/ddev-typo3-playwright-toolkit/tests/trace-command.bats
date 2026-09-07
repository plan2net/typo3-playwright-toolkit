#!/usr/bin/env bats

ADDON_DIR="$(cd "$(dirname "${BATS_TEST_FILENAME}")/.." && pwd)"

setup() {
    export PW_ADDON_CONFIG_DIR="${ADDON_DIR}"
    export PW_TEST_DIR="${BATS_TEST_TMPDIR}/project/tests/playwright"
    export DDEV_PRIMARY_URL="https://example.ddev.site:8443"
    export TRACE_CALLS="${BATS_TEST_TMPDIR}/calls"
    unset PW_SKIP_BUILD PW_SKIP_PREPARE TRACE_EXIT
    mkdir -p "${PW_TEST_DIR}" "${BATS_TEST_TMPDIR}/bin"
    cp "${ADDON_DIR}/tests/fixtures/trace-npx.sh" "${BATS_TEST_TMPDIR}/bin/npx"
    chmod +x "${BATS_TEST_TMPDIR}/bin/npx"
    export PATH="${BATS_TEST_TMPDIR}/bin:${PATH}"
    COMMAND="${ADDON_DIR}/commands/web/playwright"
    cd "${BATS_TEST_TMPDIR}/project" || exit 1
}

@test "the trace viewer names a URL a browser can open, not the bind address" {
    touch "${PW_TEST_DIR}/trace.zip"
    cp "${ADDON_DIR}/tests/fixtures/serve-npx.sh" "${BATS_TEST_TMPDIR}/bin/npx"
    chmod +x "${BATS_TEST_TMPDIR}/bin/npx"

    run "${COMMAND}" trace trace.zip

    [ "$status" -eq 0 ]
    [[ "$output" == *'https://example.ddev.site:9325'* ]] || return 1
    [[ "$output" != *'0.0.0.0:9325'* ]] || return 1
}

@test "trace serves a selected file without preparing the database" {
    touch "${PW_TEST_DIR}/downloaded trace.zip"

    run "${COMMAND}" trace "downloaded trace.zip"

    [ "$status" -eq 0 ]
    [[ "$output" == *'https://example.ddev.site:9325'* ]] || return 1
    [[ "$output" == *'downloaded trace.zip'* ]] || return 1
    run cat "${TRACE_CALLS}"
    [ "$output" = $'playwright\nshow-trace\n--host\n0.0.0.0\n--port\n9325\n--\ndownloaded trace.zip' ]
}

@test "trace picks the newest trace.zip from nested test results" {
    mkdir -p "${PW_TEST_DIR}/test-results/old test" "${PW_TEST_DIR}/test-results/new test"
    touch -t 202401010000 "${PW_TEST_DIR}/test-results/old test/trace.zip"
    touch -t 202501010000 "${PW_TEST_DIR}/test-results/new test/trace.zip"
    touch "${PW_TEST_DIR}/test-results/other.zip"

    run "${COMMAND}" trace

    [ "$status" -eq 0 ]
    run cat "${TRACE_CALLS}"
    [ "${lines[7]}" = 'test-results/new test/trace.zip' ]
}

@test "trace explains how to record a trace when none exists" {
    run "${COMMAND}" trace

    [ "$status" -ne 0 ]
    [[ "$output" == *'No trace.zip found'* ]] || return 1
    [[ "$output" == *'ddev playwright test --trace retain-on-failure'* ]] || return 1
    [ ! -e "${TRACE_CALLS}" ]
}

@test "trace refuses a missing file rather than opening another trace" {
    run "${COMMAND}" trace missing.zip

    [ "$status" -ne 0 ]
    [[ "$output" == *'Cannot read trace: missing.zip'* ]] || return 1
    [ ! -e "${TRACE_CALLS}" ]
}

@test "trace searches the requested output directory" {
    mkdir -p "${PW_TEST_DIR}/custom results/scenario"
    touch "${PW_TEST_DIR}/custom results/scenario/trace.zip"

    run "${COMMAND}" trace --output "custom results"

    [ "$status" -eq 0 ]
    run cat "${TRACE_CALLS}"
    [ "${lines[7]}" = 'custom results/scenario/trace.zip' ]
}

@test "trace help works before a project has a test directory" {
    export PW_TEST_DIR="${BATS_TEST_TMPDIR}/missing"

    run "${COMMAND}" trace --help

    [ "$status" -eq 0 ]
    [[ "$output" == *'Usage: ddev playwright trace'* ]] || return 1
    [[ "$output" == *'--output'* ]] || return 1
    [ ! -e "${TRACE_CALLS}" ]
}

@test "trace accepts a downloaded file relative to the project root" {
    mkdir -p downloads
    touch 'downloads/CI trace.zip'

    run "${COMMAND}" trace 'downloads/CI trace.zip'

    [ "$status" -eq 0 ]
    run cat "${TRACE_CALLS}"
    [ "${lines[7]}" = "${PWD}/downloads/CI trace.zip" ]
}

@test "trace rejects extra files and missing output values" {
    touch "${PW_TEST_DIR}/one.zip" "${PW_TEST_DIR}/two.zip"

    run "${COMMAND}" trace one.zip two.zip
    [ "$status" -ne 0 ]
    [ ! -e "${TRACE_CALLS}" ]

    run "${COMMAND}" trace --output
    [ "$status" -ne 0 ]
    [[ "$output" == *'--output needs a directory'* ]] || return 1
}

@test "trace prints the HTTP port when the project uses HTTP" {
    export DDEV_PRIMARY_URL="http://example.ddev.site:8080"
    touch "${PW_TEST_DIR}/trace.zip"

    run "${COMMAND}" trace trace.zip

    [ "$status" -eq 0 ]
    [[ "$output" == *'http://example.ddev.site:9326'* ]] || return 1
}
