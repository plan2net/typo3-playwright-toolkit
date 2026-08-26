#!/usr/bin/env bats

# Hermetic tests for the replay command. A stub `typo3` records what it was asked
# to do, so these need neither Docker nor a TYPO3 installation.

ADDON_DIR="$(cd "$(dirname "${BATS_TEST_FILENAME}")/.." && pwd)"

setup() {
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

@test "rebuilds the site database in the Testing context" {
    run playwright_replay_prepare "${HTML}"
    [ "$status" -eq 0 ]

    run cat "${CALLS}"
    [ "${lines[0]}" = 'Testing playwright:replay-prepare' ]
}

@test "fails when the rebuild fails" {
    PW_STUB_EXIT=1
    export PW_STUB_EXIT

    run playwright_replay_prepare "${HTML}"
    [ "$status" -ne 0 ]
}

# Every scenario shares one database and one pre-seeded session, so a second
# worker would clobber the first one's route token.
@test "refuses a worker count of its own" {
    for argument in --workers=4 "--workers 4" "-j 4" -j4; do
        # shellcheck disable=SC2086  # the two-word cases must split
        run playwright_refuse_worker_override ${argument}
        [ "$status" -ne 0 ]
        [[ "$output" == *'workers'* ]]
    done
}

@test "accepts arguments that are not a worker count" {
    run playwright_refuse_worker_override test --grep 'two words' --project=chromium
    [ "$status" -eq 0 ]
}

@test "the command declares ExecRaw so DDEV does not re-split its arguments" {
    grep -q '^## ExecRaw: true' "${ADDON_DIR}/commands/web/playwright-replay"
}

@test "the command pins a single worker" {
    grep -q -- '--workers=1' "${ADDON_DIR}/commands/web/playwright-replay"
}

@test "the lib is sourced before anything it provides is called" {
    local source_line first_call
    source_line=$(grep -n 'playwright-lib.sh' "${ADDON_DIR}/commands/web/playwright-replay" | head -1 | cut -d: -f1)
    first_call=$(grep -n 'playwright_' "${ADDON_DIR}/commands/web/playwright-replay" \
        | grep -v 'playwright-lib.sh' | head -1 | cut -d: -f1)
    [ -n "${source_line}" ]
    [ "${source_line}" -lt "${first_call}" ]
}

@test "the template is prepared before the site database is rebuilt" {
    local template_line replay_line
    template_line=$(grep -n 'playwright_prepare_template' "${ADDON_DIR}/commands/web/playwright-replay" | head -1 | cut -d: -f1)
    replay_line=$(grep -n 'playwright_replay_prepare' "${ADDON_DIR}/commands/web/playwright-replay" | head -1 | cut -d: -f1)
    [ -n "${template_line}" ]
    [ "${template_line}" -lt "${replay_line}" ]
}

# It has to work in a project that has no Playwright directory yet.
@test "--help answers before the directory change" {
    local help_line enter_line
    help_line=$(grep -n '\-\-help' "${ADDON_DIR}/commands/web/playwright-replay" | head -1 | cut -d: -f1)
    enter_line=$(grep -n 'playwright_enter_test_dir' "${ADDON_DIR}/commands/web/playwright-replay" | head -1 | cut -d: -f1)
    [ "${help_line}" -lt "${enter_line}" ]
}

@test "it exports PW_REPLAY for the toolkit" {
    grep -q 'PW_REPLAY=1' "${ADDON_DIR}/commands/web/playwright-replay"
}

@test "arguments reach npx as an array, never as a re-split string" {
    grep -q 'PW_ARGS\[@\]' "${ADDON_DIR}/commands/web/playwright-replay"
    run grep -c 'eval' "${ADDON_DIR}/commands/web/playwright-replay"
    [ "$output" -eq 0 ]
}
