#!/usr/bin/env bats

ADDON_DIR="$(cd "$(dirname "${BATS_TEST_FILENAME}")/.." && pwd)"
TEST_ID="ABCD1234EFGH5678"

setup_fixture() {
    local webserver_type="$1"
    # Deliberately stable, not $$-suffixed: DDEV adds an unresolvable hostname to
    # /etc/hosts with sudo, and a new name per run means a password prompt every
    # run plus an /etc/hosts entry that `ddev delete` leaves behind. Two fixed
    # names cost that once. The trade-off is that two runs cannot overlap, which
    # they could not anyway — they would collide on the router's ports.
    PROJNAME="t3pw-smoke-${webserver_type%%-*}"
    TESTDIR="${BATS_TMPDIR}/${PROJNAME}"

    # A killed run leaves the project registered against a temp dir that is then
    # deleted, and its containers still mount paths from it. Start from nothing.
    ddev delete -Oy "${PROJNAME}" >/dev/null 2>&1 || true
    rm -rf "${TESTDIR}"

    mkdir -p "${TESTDIR}/public"
    cp "${ADDON_DIR}/tests/fixtures/test-id-probe.php" "${TESTDIR}/public/index.php"
    cd "${TESTDIR}" || return 1
    ddev config \
        --project-name="${PROJNAME}" \
        --project-type=php \
        --docroot=public \
        --webserver-type="${webserver_type}" \
        --database=postgres:16
    ddev config --web-environment-add="TYPO3_CONTEXT=Testing"
    ddev start -y
    ddev add-on get "${ADDON_DIR}"
    ddev restart -y
}

teardown_fixture() {
    cd "${TESTDIR}" || return 0
    ddev delete -Oy "${PROJNAME}" || true
    cd "${BATS_TMPDIR}" || true
    rm -rf "${TESTDIR}"
}

assert_chain() {
    BASE_URL="https://${PROJNAME}.${DDEV_TLD:-ddev.site}"

    run ddev exec --service db-test pg_isready -U db
    [ "$status" -eq 0 ]

    run curl -sfk -H "X-Playwright-Test-Id: ${TEST_ID}" "${BASE_URL}/"
    [ "$status" -eq 0 ]
    echo "$output" | grep -q "HTTP_X_PLAYWRIGHT_TEST_ID=${TEST_ID}" \
        || { echo "header auto-forward broken; got: ${output}"; return 1; }
    echo "$output" | grep -q "CONNECTED_DB=db${TEST_ID}" \
        || { echo "header->DB-name->isolated-DB link broken; got: ${output}"; return 1; }
    echo "$output" | grep -q "RESULT=ok" \
        || { echo "DB round-trip failed; got: ${output}"; return 1; }

    run curl -sfk "${BASE_URL}/"
    [ "$status" -eq 0 ]
    echo "$output" | grep -q "RESULT=no-test-id" \
        || { echo "request without header should yield no-test-id; got: ${output}"; return 1; }
}

@test "apache-fpm: add-on installs and the full test-id chain works" {
    setup_fixture "apache-fpm"
    assert_chain
    teardown_fixture
}

@test "nginx-fpm: add-on installs and the full test-id chain works" {
    setup_fixture "nginx-fpm"
    assert_chain
    teardown_fixture
}
