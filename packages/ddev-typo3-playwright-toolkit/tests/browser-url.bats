#!/usr/bin/env bats

# A project with several hostnames used to get all of them in one address, with the
# port on the last. Hermetic: no Docker.

ADDON_DIR="$(cd "$(dirname "${BATS_TEST_FILENAME}")/.." && pwd)"

setup() {
    unset DDEV_HOSTNAME DDEV_PRIMARY_URL DDEV_SITENAME
    # shellcheck source=../playwright-lib.sh
    . "${ADDON_DIR}/playwright-lib.sh"
}

@test "serves on the primary hostname" {
    DDEV_PRIMARY_URL="https://a-project.ddev.site"

    [ "$(playwright_serve_url 9323)" = "https://a-project.ddev.site:9323" ]
}

@test "takes one hostname when the project has several" {
    DDEV_PRIMARY_URL="https://a-project.ddev.site"
    DDEV_HOSTNAME="a-project.ddev.site,forum.ddev.site,a-project-testing.ddev.site"

    [ "$(playwright_serve_url 9323)" = "https://a-project.ddev.site:9323" ]
}

@test "replaces the router's port with the one being served" {
    DDEV_PRIMARY_URL="https://a-project.ddev.site:8443"

    [ "$(playwright_serve_url 9323)" = "https://a-project.ddev.site:9323" ]
}

@test "keeps the scheme the project answers on" {
    DDEV_PRIMARY_URL="http://a-project.ddev.site:8080"

    [ "$(playwright_serve_url 9324)" = "http://a-project.ddev.site:9324" ]
}

@test "falls back to the project name when DDEV tells us nothing" {
    DDEV_SITENAME="a-project"

    [ "$(playwright_serve_url 3000)" = "https://a-project.ddev.site:3000" ]
}

@test "no command builds a URL out of the hostname list" {
    ! grep -rn 'DDEV_HOSTNAME' "${ADDON_DIR}/commands/"
}

@test "every command reaching a served port goes through the helper" {
    for command in playwright playwright-ui; do
        run grep -c 'playwright_serve_url' "${ADDON_DIR}/commands/web/${command}"
        [ "${status}" -eq 0 ]
    done
}
