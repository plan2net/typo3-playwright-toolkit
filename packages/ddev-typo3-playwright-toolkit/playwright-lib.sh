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

# Not DDEV_HOSTNAME: DDEV redefines that as a comma-separated list of every hostname
# once a project sets additional_hostnames or additional_fqdns. DDEV_PRIMARY_URL is
# one URL, but carries the router's own port unless that is 443, so the host has to be
# taken out rather than appended to.
playwright_serve_url() {
    local port="${1}"
    local url="${DDEV_PRIMARY_URL:-https://${DDEV_SITENAME}.ddev.site}"
    local scheme="https"

    case "${url}" in
        http://*) scheme="http" ;;
    esac

    url="${url#*://}"
    url="${url%%/*}"
    url="${url%%:*}"

    printf '%s://%s:%s' "${scheme}" "${url}" "${port}"
}

# Playwright's viewers announce the 0.0.0.0 they bound to, which no browser on the
# host can open. The text is the server's own, so the stream is where it changes.
# Read and print rather than `sed -u`: the line must appear while the server runs,
# and -u is GNU, which the hermetic tests would lose on macOS.
playwright_reachable_url() {
    local bind="http://0.0.0.0:${1}"
    local url="${2}"
    local line

    while IFS= read -r line || [ -n "${line}" ]; do
        printf '%s\n' "${line//${bind}/${url}}"
    done
}

# Where the project keeps its Playwright tests. An environment variable rather
# than a flag: this one belongs to the project, not to a single run, so it is set
# once in web_environment or .ddev/.env.web.
playwright_enter_test_dir() {
    local directory="${PW_TEST_DIR:-tests/playwright}"

    # Not created here on purpose: an empty directory gets past this message and
    # fails inside Playwright instead, which is a worse place to find out.
    if ! cd "${directory}" 2>/dev/null; then
        echo "[playwright] No '${directory}' directory under $(pwd). Create it with:" >&2
        echo "[playwright]   mkdir -p ${directory} && cd ${directory}" >&2
        echo "[playwright]   ddev npm init -y && ddev npm pkg set type=module" >&2
        echo "[playwright]   ddev npm i -D @plan2net/typo3-playwright-toolkit @playwright/test" >&2
        echo "[playwright] Tests elsewhere? Set PW_TEST_DIR in web_environment or .ddev/.env.web." >&2
        return 1
    fi
}

# Sets PW_ARGS to everything Playwright should receive, taking out only the flags
# this add-on handles itself.
#
# Flags rather than environment variables: these are *web* commands, so a
# host-side `PW_X=1 ddev playwright …` never reaches them.
playwright_collect_args() {
    PW_ARGS=()

    while [ $# -gt 0 ]; do
        case "$1" in
            --no-cleanup)
                export NO_DATABASE_CLEANUP=1
                ;;
            --skip-build)
                export PW_SKIP_BUILD=1
                ;;
            # The default now. Swallowed, not forwarded: npx has no such flag.
            --build)
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

# shellcheck disable=SC2034
playwright_resolve_approve_args() {
    PW_APPROVE_ARGS=("test")
    local raw_args=()
    local has_all=0
    local has_target=0

    while [ $# -gt 0 ]; do
        case "$1" in
            --all)
                has_all=1
                ;;
            --update-snapshots|-u)
                ;;
            --grep=*)
                has_target=1
                raw_args+=("$1")
                ;;
            --grep|-g)
                has_target=1
                raw_args+=("$1")
                if [ $# -gt 1 ]; then
                    shift
                    raw_args+=("$1")
                fi
                ;;
            --project|--project=*)
                raw_args+=("$1")
                while [ $# -gt 1 ] && [[ "$2" != -* ]]; do
                    shift
                    raw_args+=("$1")
                done
                ;;
            --config|-c|--output|--workers|-j|--grep-invert|-G|\
            --reporter|--add-reporter|--retries|--timeout|--global-timeout|\
            --max-failures|--repeat-each|--shard|--trace|--tsconfig|\
            --ui-host|--ui-port|--update-source-method|--last-failed-file|\
            --test-list|--test-list-invert)
                raw_args+=("$1")
                if [ $# -gt 1 ]; then
                    shift
                    raw_args+=("$1")
                fi
                ;;
            --*=*|--debug|--fail-on-flaky-tests|--forbid-only|--fully-parallel|\
            --headed|--last-failed|--list|--no-deps|--pass-with-no-tests|\
            --quiet|--ui|-x|-j[0-9]*|-c?*|-G?*)
                raw_args+=("$1")
                ;;
            -g?*)
                has_target=1
                raw_args+=("$1")
                ;;
            -*)
                echo "[playwright] Unsupported approve option: $1" >&2
                return 1
                ;;
            *)
                has_target=1
                raw_args+=("$1")
                ;;
        esac
        shift
    done

    if [ "${has_target}" -eq 0 ] && [ "${has_all}" -eq 0 ]; then
        PW_APPROVE_ARGS+=("--last-failed")
    fi

    PW_APPROVE_ARGS+=("${raw_args[@]}")
    PW_APPROVE_ARGS+=("--update-snapshots")
}

# The compose file only takes effect after `ddev restart`. Before that "db-test"
# does not resolve, or resolves to another project's service on the shared ddev
# network — and prepare would write into that project's template.
playwright_require_db_test() {
    config_dir="${PW_ADDON_CONFIG_DIR:-/mnt/ddev_config}"

    if [ -f "${config_dir}/docker-compose.db-test.yaml" ] && [ -z "${PLAYWRIGHT_DB_TEST_HOST:-}" ]; then
        echo "[playwright] The db-test service is configured but not active in this web container." >&2
        echo "[playwright] Run 'ddev restart' once to enable the add-on, then try again." >&2
        return 1
    fi
}

# The first step that touches the Testing database, so a project that never built one
# fails here, with a Doctrine trace that names no cause.
playwright_flush_caches() {
    flush_output="$( (cd "$1" && TYPO3_CONTEXT=Testing ./vendor/bin/typo3 cache:flush) 2>&1 )" && return 0

    printf '%s\n' "${flush_output}" >&2

    case "${flush_output}" in
        *TableNotFoundException*|*'Base table or view not found'*|*'does not exist'*)
            cat >&2 <<'HINT'

[playwright] The Testing context reached a database with no TYPO3 tables.
[playwright] Either build the schema there:
[playwright]     ddev exec 'TYPO3_CONTEXT=Testing ./vendor/bin/typo3 database:updateschema'
[playwright] or point the Testing context at the database your project already uses.
HINT
            ;;
    esac

    return 1
}

playwright_run_prepare() {
    playwright_require_db_test || return 1

    # $1 optional project root (empty for the default); the rest reaches playwright:prepare.
    prepare_root="${1:-/var/www/html}"
    [ $# -gt 0 ] && shift

    echo "[playwright] Flushing Testing-context TYPO3 caches…"
    playwright_flush_caches "${prepare_root}" || return 1

    echo "[playwright] Preparing the test database template…"
    (cd "${prepare_root}" && TYPO3_CONTEXT=Testing ./vendor/bin/typo3 playwright:prepare "$@") || return 1
}

playwright_replay_prepare() {
    playwright_require_db_test || return 1

    # $1 optional project root, so this is testable outside the web container.
    replay_root="${1:-/var/www/html}"

    echo "[playwright] Rebuilding the replay database on the db-test service…"
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
