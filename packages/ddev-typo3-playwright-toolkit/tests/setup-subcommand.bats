#!/usr/bin/env bats

# The setup subcommand runs before anything needs the Playwright directory, since
# a project that has not been set up yet does not have one.

ADDON_DIR="$(cd "$(dirname "${BATS_TEST_FILENAME}")/.." && pwd)"

setup() {
    COMMAND="${ADDON_DIR}/commands/web/playwright"
}

@test "setup is handled before the command enters the test directory" {
    setup_line="$(grep -n 'playwright:setup' "${COMMAND}" | head -1 | cut -d: -f1)"
    enter_line="$(grep -n 'playwright_enter_test_dir' "${COMMAND}" | head -1 | cut -d: -f1)"

    [ -n "${setup_line}" ]
    [ -n "${enter_line}" ]
    [ "${setup_line}" -lt "${enter_line}" ]
}

@test "setup names the composer command when the extension is not installed" {
    run grep -c 'composer require --dev plan2net/playwright-toolkit' "${COMMAND}"

    [ "$output" -gt 0 ]
}

@test "setup drops its own word and forwards the rest" {
    run grep -c 'exec .*vendor/bin/typo3 playwright:setup "\$@"' "${COMMAND}"

    [ "$output" -gt 0 ]
}

# A project may apply the toolkit settings for the Testing context only.
@test "setup runs in the Testing context" {
    run grep -c 'TYPO3_CONTEXT=Testing.*playwright:setup' "${COMMAND}"

    [ "$output" -gt 0 ]
}
