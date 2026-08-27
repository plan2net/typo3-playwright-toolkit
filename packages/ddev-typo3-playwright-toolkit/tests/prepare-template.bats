#!/usr/bin/env bats

# Hermetic tests for the template preflight. A stub `typo3` records what it was
# asked to do, so these need neither Docker nor a TYPO3 installation.

ADDON_DIR="$(cd "$(dirname "${BATS_TEST_FILENAME}")/.." && pwd)"

setup() {
    unset PW_SKIP_PREPARE

    # The lib cds into /var/www/html, which must exist and hold the stub binary.
    HTML="${BATS_TEST_TMPDIR}/var/www/html"
    mkdir -p "${HTML}/vendor/bin"
    CALLS="${BATS_TEST_TMPDIR}/calls.txt"

    cat > "${HTML}/vendor/bin/typo3" <<STUB
#!/bin/sh
echo "\$TYPO3_CONTEXT \$*" >> "${CALLS}"
exit \${PW_STUB_EXIT:-0}
STUB
    chmod +x "${HTML}/vendor/bin/typo3"

    # shellcheck source=../playwright-lib.sh
    . "${ADDON_DIR}/playwright-lib.sh"
}

@test "flushes the cache and then prepares the template" {
    run playwright_prepare_template "${HTML}"
    [ "$status" -eq 0 ]

    run cat "${CALLS}"
    [ "${lines[0]}" = 'Testing cache:flush' ]
    [ "${lines[1]}" = 'Testing playwright:prepare' ]
}

@test "runs both steps in the Testing context, never the default one" {
    playwright_prepare_template "${HTML}"

    run grep -c '^Testing ' "${CALLS}"
    [ "$output" -eq 2 ]
}

@test "uses two separate processes so the second reloads TCA from scratch" {
    playwright_prepare_template "${HTML}"

    run wc -l < "${CALLS}"
    [ "$output" -eq 2 ]
}

@test "PW_SKIP_PREPARE=1 skips both steps and says so" {
    PW_SKIP_PREPARE=1
    export PW_SKIP_PREPARE

    run playwright_prepare_template "${HTML}"
    [ "$status" -eq 0 ]
    [[ "$output" == *'PW_SKIP_PREPARE=1'* ]]
    [ ! -f "${CALLS}" ]
}

@test "fails when the cache flush fails, without preparing" {
    PW_STUB_EXIT=1
    export PW_STUB_EXIT

    run playwright_prepare_template "${HTML}"
    [ "$status" -ne 0 ]

    run wc -l < "${CALLS}"
    [ "$output" -eq 1 ]
}

@test "every command that runs tests prepares the template first" {
    for command in playwright playwright-ui; do
        grep -q 'playwright_prepare_template' "${ADDON_DIR}/commands/web/${command}"
    done
}

# The bare flush was what these commands used to do; leaving one behind would
# mean a command that flushes but never rebuilds.
@test "no command still calls cache:flush directly" {
    for command in playwright playwright-ui playwright-prepare; do
        run grep -c 'typo3 cache:flush' "${ADDON_DIR}/commands/web/${command}"
        [ "$output" -eq 0 ]
    done
}

@test "the lib is sourced before the prepare call in every command" {
    for command in playwright playwright-ui; do
        local source_line prepare_line
        source_line=$(grep -n 'playwright-lib.sh' "${ADDON_DIR}/commands/web/${command}" | head -1 | cut -d: -f1)
        prepare_line=$(grep -n 'playwright_prepare_template' "${ADDON_DIR}/commands/web/${command}" | head -1 | cut -d: -f1)
        [ -n "${source_line}" ]
        [ -n "${prepare_line}" ]
        [ "${source_line}" -lt "${prepare_line}" ]
    done
}

# PW_SKIP_PREPARE is there to make a test run reuse the template; asking for a
# build by hand and silently getting nothing would be the opposite.
@test "the standalone prepare command uses the ungated helper" {
    run grep -c 'playwright_run_prepare' "${ADDON_DIR}/commands/web/playwright-prepare"
    [ "$output" -eq 1 ]

    run grep -c 'playwright_prepare_template' "${ADDON_DIR}/commands/web/playwright-prepare"
    [ "$output" -eq 0 ]
}

@test "refuses to prepare when the db-test service is configured but not active yet" {
    export PW_ADDON_CONFIG_DIR="${BATS_TEST_TMPDIR}/ddev-config"
    mkdir -p "${PW_ADDON_CONFIG_DIR}"
    touch "${PW_ADDON_CONFIG_DIR}/docker-compose.db-test.yaml"
    unset PLAYWRIGHT_DB_TEST_HOST

    run playwright_run_prepare "${HTML}"
    [ "$status" -ne 0 ]
    [[ "$output" == *'ddev restart'* ]]
    [ ! -f "${CALLS}" ]
}

@test "prepares once the db-test service is active in the web container" {
    export PW_ADDON_CONFIG_DIR="${BATS_TEST_TMPDIR}/ddev-config"
    mkdir -p "${PW_ADDON_CONFIG_DIR}"
    touch "${PW_ADDON_CONFIG_DIR}/docker-compose.db-test.yaml"
    export PLAYWRIGHT_DB_TEST_HOST=db-test

    run playwright_run_prepare "${HTML}"
    [ "$status" -eq 0 ]
}

@test "prepares on a project without the db-test service" {
    export PW_ADDON_CONFIG_DIR="${BATS_TEST_TMPDIR}/ddev-config"
    mkdir -p "${PW_ADDON_CONFIG_DIR}"
    unset PLAYWRIGHT_DB_TEST_HOST

    run playwright_run_prepare "${HTML}"
    [ "$status" -eq 0 ]
}

@test "the replay preparation is guarded the same way" {
    export PW_ADDON_CONFIG_DIR="${BATS_TEST_TMPDIR}/ddev-config"
    mkdir -p "${PW_ADDON_CONFIG_DIR}"
    touch "${PW_ADDON_CONFIG_DIR}/docker-compose.db-test.yaml"
    unset PLAYWRIGHT_DB_TEST_HOST

    run playwright_replay_prepare "${HTML}"
    [ "$status" -ne 0 ]
    [[ "$output" == *'ddev restart'* ]]
    [ ! -f "${CALLS}" ]
}

@test "the ungated helper forwards extra arguments to the prepare call" {
    run playwright_run_prepare "${HTML}" --force
    [ "$status" -eq 0 ]

    run tail -1 "${CALLS}"
    [ "$output" = 'Testing playwright:prepare --force' ]
}

@test "the standalone prepare command forwards its arguments" {
    grep -q 'playwright_run_prepare "" "$@"' "${ADDON_DIR}/commands/web/playwright-prepare"
}

@test "the ungated helper prepares even when PW_SKIP_PREPARE is set" {
    PW_SKIP_PREPARE=1
    export PW_SKIP_PREPARE

    run playwright_run_prepare "${HTML}"
    [ "$status" -eq 0 ]

    run wc -l < "${CALLS}"
    [ "$output" -eq 2 ]
}

# The standalone command duplicated the two typo3 calls before the split.
@test "no command spells out the typo3 prepare call itself" {
    for command in playwright playwright-ui playwright-prepare; do
        run grep -c 'playwright:prepare' "${ADDON_DIR}/commands/web/${command}"
        [ "$output" -eq 0 ]
    done
}
