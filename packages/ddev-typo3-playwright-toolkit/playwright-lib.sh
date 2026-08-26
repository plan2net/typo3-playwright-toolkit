#!/bin/bash
#ddev-generated

# Shared helpers for the `ddev playwright*` commands. Sourced from
# /mnt/ddev_config/playwright-lib.sh inside the web container.
#
# Bash, not sh: arguments reach Playwright through arrays so a --grep pattern
# with spaces stays one argument.

# DDEV sets NO_COLOR for web commands, which would strip Playwright's own
# colouring from a run a developer is watching.
unset NO_COLOR

# Where the project keeps its Playwright tests. An environment variable rather
# than a flag: this one belongs to the project, not to a single run, so it is set
# once in web_environment or .ddev/.env.web.
playwright_enter_test_dir() {
    local directory="${PW_TEST_DIR:-tests/playwright}"

    if ! cd "${directory}" 2>/dev/null; then
        echo "[playwright] No '${directory}' directory under $(pwd)." >&2
        echo "[playwright] Set PW_TEST_DIR in web_environment or .ddev/.env.web to point at your Playwright tests." >&2
        return 1
    fi
}

# Sets PW_ARGS to everything Playwright should receive, taking out only the flags
# this add-on handles itself.
#
# Flags rather than environment variables: these are *web* commands, so a
# host-side `PW_X=1 ddev playwright …` never reaches them.
#
# shellcheck disable=SC2034  # PW_BUILD is read by the sourcing command
playwright_collect_args() {
    PW_ARGS=()
    PW_BUILD=0

    while [ $# -gt 0 ]; do
        case "$1" in
            --no-cleanup)
                export NO_DATABASE_CLEANUP=1
                ;;
            --build)
                PW_BUILD=1
                ;;
            --skip-prepare)
                export PW_SKIP_PREPARE=1
                ;;
            *)
                PW_ARGS+=("$1")
                ;;
        esac
        shift
    done
}

playwright_build_assets() {
    build_root="${1:-/var/www/html}"

    echo "[playwright] Building frontend assets…"
    (cd "${build_root}" && npm run build) || return 1
}

playwright_run_prepare() {
    # $1 optional project root, so this is testable outside the web container.
    prepare_root="${1:-/var/www/html}"

    echo "[playwright] Flushing Testing-context TYPO3 caches…"
    (cd "${prepare_root}" && TYPO3_CONTEXT=Testing ./vendor/bin/typo3 cache:flush) || return 1

    echo "[playwright] Preparing the test database template…"
    (cd "${prepare_root}" && TYPO3_CONTEXT=Testing ./vendor/bin/typo3 playwright:prepare) || return 1
}

playwright_replay_prepare() {
    # $1 optional project root, so this is testable outside the web container.
    replay_root="${1:-/var/www/html}"

    echo "[playwright] Rebuilding the testing site database from the template…"
    (cd "${replay_root}" && TYPO3_CONTEXT=Testing ./vendor/bin/typo3 playwright:replay-prepare) || return 1
}

# One database and one pre-seeded session are shared by every scenario, so a
# second worker would clobber the first one's route token.
playwright_refuse_worker_override() {
    while [ $# -gt 0 ]; do
        case "$1" in
            --workers|--workers=*|-j|-j[0-9]*)
                echo "[playwright] replay runs with --workers=1; remove '$1'." >&2
                return 1
                ;;
        esac
        shift
    done
}

playwright_prepare_template() {
    if [ "${PW_SKIP_PREPARE:-}" = "1" ]; then
        echo "[playwright] PW_SKIP_PREPARE=1 — keeping the existing test database template"
        return 0
    fi

    playwright_run_prepare "$@"
}
