#!/usr/bin/env bats

# Where a project keeps its Playwright tests is the project's decision. The
# commands used to hardcode one path, so a project using anything else could not
# run them at all. Hermetic: no Docker.

ADDON_DIR="$(cd "$(dirname "${BATS_TEST_FILENAME}")/.." && pwd)"

setup() {
    unset PW_TEST_DIR
    PROJECT="$(mktemp -d)"
    # shellcheck source=../playwright-lib.sh
    . "${ADDON_DIR}/playwright-lib.sh"
}

teardown() {
    rm -rf "${PROJECT}"
}

@test "enters tests/playwright when nothing is configured" {
    mkdir -p "${PROJECT}/tests/playwright"
    cd "${PROJECT}"

    playwright_enter_test_dir

    [ "$(pwd -P)" = "$(cd "${PROJECT}/tests/playwright" && pwd -P)" ]
}

@test "enters the directory PW_TEST_DIR names" {
    mkdir -p "${PROJECT}/e2e"
    cd "${PROJECT}"
    export PW_TEST_DIR=e2e

    playwright_enter_test_dir

    [ "$(pwd -P)" = "$(cd "${PROJECT}/e2e" && pwd -P)" ]
}

@test "accepts a directory that is several levels down" {
    mkdir -p "${PROJECT}/config/test/playwright"
    cd "${PROJECT}"
    export PW_TEST_DIR=config/test/playwright

    playwright_enter_test_dir

    [ "$(pwd -P)" = "$(cd "${PROJECT}/config/test/playwright" && pwd -P)" ]
}

@test "keeps a directory name containing a space in one piece" {
    mkdir -p "${PROJECT}/my tests"
    cd "${PROJECT}"
    export PW_TEST_DIR="my tests"

    playwright_enter_test_dir

    [ "$(pwd -P)" = "$(cd "${PROJECT}/my tests" && pwd -P)" ]
}

@test "fails when the directory does not exist" {
    cd "${PROJECT}"

    run playwright_enter_test_dir

    [ "$status" -eq 1 ]
}

# Without the variable named, the reader has no way to know the path is theirs
# to choose — which is the whole bug this replaces.
@test "names PW_TEST_DIR and the missing path when it fails" {
    cd "${PROJECT}"
    export PW_TEST_DIR=e2e

    run playwright_enter_test_dir

    [[ "${output}" == *"PW_TEST_DIR"* ]]
    [[ "${output}" == *"e2e"* ]]
}

@test "no command hardcodes a test directory of its own" {
    for command in "${ADDON_DIR}"/commands/web/*; do
        run grep -nE '^[[:space:]]*cd[[:space:]]+[^"$]' "${command}"
        [ "$status" -ne 0 ] || {
            echo "$(basename "${command}") cds to a fixed path: ${output}"
            false
        }
    done
}

@test "every command that changes directory goes through the helper" {
    for command in "${ADDON_DIR}"/commands/web/*; do
        grep -q 'npx' "${command}" || continue

        run grep -q 'playwright_enter_test_dir' "${command}"
        [ "$status" -eq 0 ] || {
            echo "$(basename "${command}") does not use playwright_enter_test_dir"
            false
        }
    done
}
