#!/usr/bin/env bats

# Hermetic tests for the db-test service selection. These source the script and
# run it against a temporary directory, so they need neither Docker nor DDEV.

ADDON_DIR="$(cd "$(dirname "${BATS_TEST_FILENAME}")/.." && pwd)"

setup() {
    # shellcheck source=../db-test/select-service.sh
    . "${ADDON_DIR}/db-test/select-service.sh"

    WORKDIR="${BATS_TEST_TMPDIR}/ddev"
    mkdir -p "${WORKDIR}/db-test"
    cp "${ADDON_DIR}"/db-test/docker-compose.*.yaml "${WORKDIR}/db-test/"
    cd "${WORKDIR}" || exit 1
}

@test "maps each supported database type to its own service" {
    run playwright_db_test_source 'postgres:16'
    [ "$output" = 'db-test/docker-compose.postgres.yaml' ]

    run playwright_db_test_source 'mariadb:11'
    [ "$output" = 'db-test/docker-compose.mariadb.yaml' ]

    run playwright_db_test_source 'mysql:8.0'
    [ "$output" = 'db-test/docker-compose.mysql.yaml' ]
}

@test "ignores the version, which does not select a service" {
    run playwright_db_test_source 'mariadb:10.11'
    [ "$output" = 'db-test/docker-compose.mariadb.yaml' ]

    run playwright_db_test_source 'postgres:15'
    [ "$output" = 'db-test/docker-compose.postgres.yaml' ]
}

@test "refuses a database type it ships no service for" {
    run playwright_db_test_source 'sqlite:3'
    [ "$status" -ne 0 ]
}

@test "installs the matching service at the top level, where ddev loads it" {
    run playwright_install_db_test_service 'mariadb:11'
    [ "$status" -eq 0 ]
    [ -f 'docker-compose.db-test.yaml' ]
    grep -q 'mariadb:11' docker-compose.db-test.yaml
    grep -q 'mariadbd' docker-compose.db-test.yaml
}

@test "installs postgres for a postgres project" {
    run playwright_install_db_test_service 'postgres:16'
    [ "$status" -eq 0 ]
    grep -q 'postgres:16-alpine' docker-compose.db-test.yaml
}

@test "names the image db-test actually runs, not the project's own version" {
    run playwright_install_db_test_service 'mariadb:10.2'

    [ "$status" -eq 0 ]
    [[ "$output" == *'mariadb:11'* ]]
}

@test "replaces a previously installed service when the database changes" {
    playwright_install_db_test_service 'postgres:16'
    playwright_install_db_test_service 'mysql:8'

    grep -q 'mysqld' docker-compose.db-test.yaml
    run grep -c 'postgres' docker-compose.db-test.yaml
    [ "$output" -eq 0 ]
}

@test "fails with an actionable message for an unsupported database" {
    run playwright_install_db_test_service 'sqlite:3'
    [ "$status" -ne 0 ]
    [[ "$output" == *'sqlite'* ]]
    [[ "$output" == *'Supported: postgres, mariadb, mysql'* ]]
    [ ! -f 'docker-compose.db-test.yaml' ]
}

@test "fails when DDEV_DATABASE is empty rather than installing nothing silently" {
    run playwright_install_db_test_service ''
    [ "$status" -ne 0 ]
    [[ "$output" == *'DDEV_DATABASE'* ]]
}

@test "every shipped service keeps the ddev-generated marker so removal can clean it" {
    for file in "${ADDON_DIR}"/db-test/docker-compose.*.yaml; do
        run head -1 "${file}"
        [ "$output" = '#ddev-generated' ]
    done
}

@test "every shipped service exports the test connection to the web container" {
    for file in "${ADDON_DIR}"/db-test/docker-compose.*.yaml; do
        grep -q 'PLAYWRIGHT_DB_TEST_HOST=db-test' "${file}"
        grep -q 'PLAYWRIGHT_DB_TEST_USER=db' "${file}"
        grep -q 'PLAYWRIGHT_DB_TEST_PASSWORD=db' "${file}"
    done
}

@test "every shipped service names the container the same way" {
    for file in "${ADDON_DIR}"/db-test/docker-compose.*.yaml; do
        grep -q 'container_name: ddev-${DDEV_SITENAME}-db-test' "${file}"
    done
}

# The port is never exported: the extension derives it from the engine, so a
# pinned value here would hand a mysql project the postgres port.
@test "no shipped service pins a port" {
    for file in "${ADDON_DIR}"/db-test/docker-compose.*.yaml; do
        run grep -c 'PLAYWRIGHT_DB_TEST_PORT' "${file}"
        [ "$output" -eq 0 ]
    done
}

# Re-running this by hand happens outside DDEV, where DDEV_DATABASE is unset —
# which is exactly what the script's own error message tells you to do.
@test "takes the database from its argument when run by hand" {
    unset DDEV_DATABASE

    run bash "${ADDON_DIR}/db-test/select-service.sh" 'postgres:16'
    [ "$status" -eq 0 ]
    grep -q 'postgres:16-alpine' docker-compose.db-test.yaml
}

@test "falls back to DDEV_DATABASE when given no argument" {
    DDEV_DATABASE='mariadb:11'
    export DDEV_DATABASE

    run bash "${ADDON_DIR}/db-test/select-service.sh"
    [ "$status" -eq 0 ]
    grep -q 'mariadb:11' docker-compose.db-test.yaml
}

@test "the mysql services mount the grant that provisioning needs" {
    for file in "${ADDON_DIR}"/db-test/docker-compose.mariadb.yaml "${ADDON_DIR}"/db-test/docker-compose.mysql.yaml; do
        grep -q 'mysql/db-test-init.sql:/docker-entrypoint-initdb.d/' "${file}"
    done
}
