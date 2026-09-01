#!/usr/bin/env bats

# Argument handling for the `ddev playwright*` commands. These flattened "$@"
# into a string and re-split it with `eval`, so a --grep with a space became two
# patterns and a title with a semicolon ran as a command. Hermetic: no Docker.

ADDON_DIR="$(cd "$(dirname "${BATS_TEST_FILENAME}")/.." && pwd)"

setup() {
    unset NO_DATABASE_CLEANUP PW_BUILD
    # shellcheck source=../playwright-lib.sh
    . "${ADDON_DIR}/playwright-lib.sh"
}

@test "keeps a quoted value with spaces as one argument" {
    playwright_collect_args --grep "three facts and a lie"

    [ "${#PW_ARGS[@]}" -eq 2 ]
    [ "${PW_ARGS[0]}" = "--grep" ]
    [ "${PW_ARGS[1]}" = "three facts and a lie" ]
}

@test "keeps an argument containing shell metacharacters intact" {
    playwright_collect_args --grep 'a; echo pwned'

    [ "${PW_ARGS[1]}" = "a; echo pwned" ]
}

@test "does not expand a glob in an argument" {
    playwright_collect_args --grep '*.setup.ts'

    [ "${PW_ARGS[1]}" = "*.setup.ts" ]
}

@test "keeps an empty argument rather than dropping it" {
    playwright_collect_args --grep ""

    [ "${#PW_ARGS[@]}" -eq 2 ]
    [ "${PW_ARGS[1]}" = "" ]
}

@test "keeps a value with a double quote in it" {
    playwright_collect_args --grep 'say "hello"'

    [ "${PW_ARGS[1]}" = 'say "hello"' ]
}

@test "takes --no-cleanup for itself instead of passing it on" {
    playwright_collect_args test --no-cleanup

    [ "${#PW_ARGS[@]}" -eq 1 ]
    [ "${PW_ARGS[0]}" = "test" ]
    [ "${NO_DATABASE_CLEANUP}" = "1" ]
}

@test "forwards --workers rather than swallowing it" {
    playwright_collect_args test --workers 4

    [ "${PW_ARGS[1]}" = "--workers" ]
    [ "${PW_ARGS[2]}" = "4" ]

    playwright_collect_args test --workers=6

    [ "${PW_ARGS[1]}" = "--workers=6" ]
}

@test "handles no arguments at all" {
    playwright_collect_args

    [ "${#PW_ARGS[@]}" -eq 0 ]
}

@test "takes --build for itself instead of passing it on" {
    playwright_collect_args test --build

    [ "${#PW_ARGS[@]}" -eq 1 ]
    [ "${PW_ARGS[0]}" = "test" ]
    [ "${PW_BUILD}" = "1" ]
}

@test "leaves --build off by default" {
    playwright_collect_args test

    [ "${PW_BUILD}" = "0" ]
}

# These are web commands, so PW_SKIP_PREPARE=1 typed on the host never arrives.
@test "takes --skip-prepare for itself instead of passing it on" {
    playwright_collect_args test --skip-prepare

    [ "${#PW_ARGS[@]}" -eq 1 ]
    [ "${PW_ARGS[0]}" = "test" ]
    [ "${PW_SKIP_PREPARE}" = "1" ]
}

@test "leaves --skip-prepare off by default" {
    playwright_collect_args test

    [ "${PW_SKIP_PREPARE:-0}" = "0" ]
}

@test "keeps PW_SKIP_PREPARE from the environment when the flag is absent" {
    export PW_SKIP_PREPARE=1
    playwright_collect_args test

    [ "${PW_SKIP_PREPARE}" = "1" ]
}

# --project is Playwright's own flag now that no staging derives project lists.
@test "forwards --project untouched" {
    playwright_collect_args test --project=firefox

    [ "${PW_ARGS[1]}" = "--project=firefox" ]
}

# `--help` has to answer in any project, including one that has not created
# tests/playwright yet — that is exactly when someone reads it.
@test "every command with help answers before changing directory" {
    for command in "${ADDON_DIR}"/commands/web/*; do
        grep -q -- '"--help"' "${command}" || continue

        local help_line cd_line
        help_line=$(grep -n -- '"--help"' "${command}" | head -1 | cut -d: -f1)
        cd_line=$(grep -n '^playwright_enter_test_dir' "${command}" | head -1 | cut -d: -f1)

        [ -n "${cd_line}" ] || {
            echo "$(basename "${command}") never enters the test directory — this test now covers nothing"
            false
        }
        [ "${help_line}" -lt "${cd_line}" ] || {
            echo "$(basename "${command}") cds on line ${cd_line} before handling --help on line ${help_line}"
            false
        }
    done
}

@test "no command reconstructs a command line with eval" {
    for command in "${ADDON_DIR}"/commands/web/*; do
        run grep -n '^[^#]*\beval\b' "${command}"
        [ "$status" -ne 0 ] || {
            echo "${command} still uses eval: ${output}"
            false
        }
    done
}

# The same bug one layer up, and the only part of it a hermetic test can reach.
# Without this annotation DDEV joins the arguments into a shell string and
# re-evaluates them before the command runs, so every argument test above passes
# while `ddev playwright test --grep "two words"` still searches for two patterns
# and `--grep 'a; echo x'` still runs the echo.
@test "every command takes its arguments raw from ddev" {
    for command in "${ADDON_DIR}"/commands/web/*; do
        run grep -qE '^## ExecRaw: true$' "${command}"
        [ "$status" -eq 0 ] || {
            echo "${command} is missing '## ExecRaw: true'"
            false
        }
    done
}

# A flattened string is the other half of the same bug: it loses boundaries even
# without eval.
@test "no command flattens arguments into a string" {
    for command in "${ADDON_DIR}"/commands/web/*; do
        run grep -nE '(ARGS|EXTRA_ARGS)="\$\{?(ARGS|EXTRA_ARGS)' "${command}"
        [ "$status" -ne 0 ] || {
            echo "${command} accumulates arguments into a string: ${output}"
            false
        }
    done
}

@test "serves the report on every interface" {
    grep -q -- '--host 0.0.0.0' "${ADDON_DIR}/commands/web/playwright"
}

@test "serves the report on the port the add-on exposes" {
    port="$(sed -n 's/.*PW_REPORT_PORT:-\([0-9]*\).*/\1/p' "${ADDON_DIR}/commands/web/playwright" | head -1)"

    [ -n "${port}" ]
    grep -q "container_port: ${port}" "${ADDON_DIR}/config.playwright-toolkit.yaml"
}
