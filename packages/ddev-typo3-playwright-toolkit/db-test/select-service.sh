#!/bin/bash
#ddev-generated

# Installs the db-test service matching the project's database. Runs as an
# add-on post-install action, and can be re-run by hand after changing the
# database type:
#
#   cd .ddev && bash db-test/select-service.sh

set -eu

playwright_db_test_type() {
    # DDEV_DATABASE is "type:version"; only the type selects a service.
    printf '%s' "${1%%:*}"
}

playwright_db_test_source() {
    case "$(playwright_db_test_type "$1")" in
        postgres) printf 'db-test/docker-compose.postgres.yaml' ;;
        mariadb) printf 'db-test/docker-compose.mariadb.yaml' ;;
        mysql) printf 'db-test/docker-compose.mysql.yaml' ;;
        *) return 1 ;;
    esac
}

playwright_install_db_test_service() {
    local database="${1:-}"
    local target='docker-compose.db-test.yaml'
    local source

    if [ -z "${database}" ]; then
        echo "typo3-playwright: DDEV_DATABASE is not set, so no db-test service was installed." >&2
        echo "  Run 'cd .ddev && bash db-test/select-service.sh <type>:<version>' to choose one." >&2
        return 1
    fi

    if ! source="$(playwright_db_test_source "${database}")"; then
        echo "typo3-playwright: no db-test service for database \"${database}\"." >&2
        echo "  Supported: postgres, mariadb, mysql. A TYPO3 project on sqlite needs no service —" >&2
        echo "  delete ${target}; the toolkit needs no configuration for it." >&2
        return 1
    fi

    cp "${source}" "${target}"
    echo "typo3-playwright: installed ${target} for ${database}."
}

if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
    playwright_install_db_test_service "${1:-${DDEV_DATABASE:-}}"
fi
