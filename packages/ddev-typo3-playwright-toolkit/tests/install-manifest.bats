#!/usr/bin/env bats

# Static checks on install.yaml. A project_files entry that does not exist makes
# `ddev add-on get` fail at install time, so verify the manifest here where it
# costs nothing instead of in the Docker-backed suite.

ADDON_DIR="$(cd "$(dirname "${BATS_TEST_FILENAME}")/.." && pwd)"

project_files() {
    awk '
        /^project_files:/ { inside = 1; next }
        /^[a-z_]+:/ { inside = 0 }
        inside && /^ *- / { sub(/^ *- /, ""); print }
    ' "${ADDON_DIR}/install.yaml"
}

# docker-compose.db-test.yaml is not shipped: select-service.sh writes it at
# install time from whichever engine file matches the project.
@test "install.yaml lists at least the service, the commands and the shared lib" {
    run project_files
    [ "$status" -eq 0 ]
    [[ "$output" == *"db-test/select-service.sh"* ]]
    [[ "$output" == *"db-test/docker-compose.postgres.yaml"* ]]
    [[ "$output" == *"playwright-lib.sh"* ]]
    [[ "$output" == *"commands/web/playwright"* ]]
}

# The other direction: a command that is not listed is never installed, and the
# test above cannot see it missing.
@test "every command is listed in install.yaml" {
    local unlisted="" listed
    listed=$(project_files)

    for command in "${ADDON_DIR}"/commands/web/*; do
        [[ "${listed}" == *"commands/web/$(basename "${command}")"* ]] || unlisted="${unlisted} $(basename "${command}")"
    done

    [ -z "${unlisted}" ] || {
        echo "commands missing from install.yaml:${unlisted}"
        false
    }
}

@test "every file listed in install.yaml project_files exists" {
    local missing=""
    while IFS= read -r file; do
        [ -n "${file}" ] || continue
        if [ ! -e "${ADDON_DIR}/${file}" ]; then
            missing="${missing} ${file}"
        fi
    done < <(project_files)

    [ -z "${missing}" ] || {
        echo "install.yaml lists files that do not exist:${missing}"
        false
    }
}

# `ddev add-on remove` refuses to delete a file without this marker, so anything
# shipped without it is left behind on the consumer's machine forever.
@test "every shipped project file carries the ddev-generated marker" {
    local missing=""
    while IFS= read -r file; do
        [ -n "${file}" ] || continue
        grep -q '#ddev-generated' "${ADDON_DIR}/${file}" || missing="${missing} ${file}"
    done < <(project_files)

    [ -z "${missing}" ] || {
        echo "shipped without a #ddev-generated marker:${missing}"
        false
    }
}

# UI mode serves a web app from inside the container, so an unexposed port makes
# `ddev playwright-ui` print a URL nothing answers on.
@test "the ui port the command serves on is the one the config exposes" {
    local served exposed
    served=$(grep -oE 'PW_UI_PORT:-[0-9]+' "${ADDON_DIR}/commands/web/playwright-ui" | grep -oE '[0-9]+')
    exposed=$(grep -A 3 'name: playwright-ui' "${ADDON_DIR}/config.playwright-toolkit.yaml" \
        | grep 'https_port:' | grep -oE '[0-9]+')

    [ -n "${served}" ]
    [ "${served}" = "${exposed}" ]
}

@test "the ui command binds to all interfaces, not container loopback" {
    grep -q -- '--ui-host=0.0.0.0' "${ADDON_DIR}/commands/web/playwright-ui"
}

@test "every command that sources the shared lib gets it installed" {
    local sourcing
    sourcing=$(grep -l 'playwright-lib.sh' "${ADDON_DIR}"/commands/web/* | wc -l | tr -d ' ')
    [ "${sourcing}" -gt 0 ]

    run project_files
    [[ "$output" == *"playwright-lib.sh"* ]]
}
