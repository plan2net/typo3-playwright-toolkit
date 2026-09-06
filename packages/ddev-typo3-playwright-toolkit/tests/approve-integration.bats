#!/usr/bin/env bats

ADDON_DIR="$(cd "$(dirname "${BATS_TEST_FILENAME}")/.." && pwd)"

setup() {
    export APPROVE_PLAYWRIGHT_CLI="${ADDON_DIR}/../typo3-playwright-toolkit/node_modules/playwright/cli.js"
    export APPROVE_PLAYWRIGHT_MODULE="${ADDON_DIR}/../typo3-playwright-toolkit/node_modules/@playwright/test"
    [ -f "${APPROVE_PLAYWRIGHT_CLI}" ]
    export PW_ADDON_CONFIG_DIR="${ADDON_DIR}"
    export PW_TEST_DIR="${BATS_TEST_TMPDIR}/project/tests/playwright"
    export PW_SKIP_PREPARE=1
    export APPROVE_CALLS="${BATS_TEST_TMPDIR}/calls"
    unset APPROVE_ACCORDION APPROVE_TEASER PLAYWRIGHT_LAST_RUN_OUTPUT_FILE
    mkdir -p "${PW_TEST_DIR}" "${BATS_TEST_TMPDIR}/bin"
    cp "${ADDON_DIR}/tests/fixtures/approve-npx.sh" "${BATS_TEST_TMPDIR}/bin/npx"
    chmod +x "${BATS_TEST_TMPDIR}/bin/npx"
    cp "${ADDON_DIR}/tests/fixtures/approve.spec.cjs" "${PW_TEST_DIR}/approve.spec.cjs"
    cp "${ADDON_DIR}/tests/fixtures/approve.config.cjs" "${PW_TEST_DIR}/custom.config.cjs"
    export PATH="${BATS_TEST_TMPDIR}/bin:${PATH}"
    COMMAND="${ADDON_DIR}/commands/web/playwright"
    cd "${PW_TEST_DIR}" || exit 1
}

@test "approval updates only previous failures using the selected config and custom output directory" {
    run node "${APPROVE_PLAYWRIGHT_CLI}" test -c custom.config.cjs --update-snapshots
    [ "$status" -eq 0 ]

    export APPROVE_ACCORDION=updated
    run node "${APPROVE_PLAYWRIGHT_CLI}" test -c custom.config.cjs
    [ "$status" -eq 1 ]

    export APPROVE_TEASER='unapproved change'
    run "${COMMAND}" approve -c custom.config.cjs
    [ "$status" -eq 0 ]
    [ "$(< baselines/accordion.txt)" = updated ]
    [ "$(< baselines/teaser.txt)" = original ]
}

@test "approval with no previous run creates no baselines" {
    run "${COMMAND}" approve -c custom.config.cjs

    [ "$status" -ne 0 ]
    [ ! -d baselines ]
}

@test "approval with no failed test IDs creates no baselines" {
    mkdir -p 'custom results'
    printf '%s' '{"status":"failed","failedTests":[]}' > 'custom results/.last-run.json'

    run "${COMMAND}" approve -c custom.config.cjs

    [ "$status" -ne 0 ]
    [ ! -d baselines ]
}

@test "explicit all approves both tests without previous run state" {
    run "${COMMAND}" approve --all -c custom.config.cjs

    [ "$status" -eq 0 ]
    [ "$(< baselines/accordion.txt)" = original ]
    [ "$(< baselines/teaser.txt)" = original ]
}

@test "approval respects an overridden last-run path instead of trusting stale default state" {
    run node "${APPROVE_PLAYWRIGHT_CLI}" test -c custom.config.cjs --update-snapshots
    [ "$status" -eq 0 ]
    export APPROVE_ACCORDION=updated
    run node "${APPROVE_PLAYWRIGHT_CLI}" test -c custom.config.cjs
    [ "$status" -eq 1 ]

    export PLAYWRIGHT_LAST_RUN_OUTPUT_FILE="${BATS_TEST_TMPDIR}/missing-state.json"
    run "${COMMAND}" approve -c custom.config.cjs

    [ "$status" -ne 0 ]
    [ "$(< baselines/accordion.txt)" = original ]
}
