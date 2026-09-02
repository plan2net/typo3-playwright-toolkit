#!/usr/bin/env bats

# Running browsers outside the web container. Playwright reads the connect
# variables itself, so the add-on documents them and ships nothing. The recipe
# lives in the monorepo README, which holds all three layouts in one place, and
# the add-on names the variables and links there. Hermetic.

ADDON_DIR="$(cd "$(dirname "${BATS_TEST_FILENAME}")/.." && pwd)"
REPO_README="$(cd "${ADDON_DIR}/../.." && pwd)/README.md"

@test "documents both connect variables Playwright reads" {
    grep -q 'PW_TEST_CONNECT_WS_ENDPOINT' "${ADDON_DIR}/README.md"
    grep -q 'PW_TEST_CONNECT_EXPOSE_NETWORK' "${ADDON_DIR}/README.md"
    grep -q 'ws://playwright-server:3000/' "${REPO_README}"
}

@test "sends the reader to the one place that has all three layouts" {
    grep -q '#where-things-run' "${ADDON_DIR}/README.md"
    grep -q '^### Where things run' "${REPO_README}"
}

# An image of ours would decide the architecture for every consumer, and pinning
# one means emulation.
@test "ships no browser container" {
    run bash -c "ls '${ADDON_DIR}'/*/docker-compose.playwright*.yaml '${ADDON_DIR}'/docker-compose.playwright*.yaml 2>/dev/null"
    [ -z "${output}" ]

    run grep -n 'playwright-server' "${ADDON_DIR}/install.yaml"
    [ "$status" -ne 0 ]
}

@test "names the pin as the consumer's decision, not a default" {
    grep -q 'platform: linux/amd64' "${REPO_README}"
}

# The runner holds the API secret and the state directory, so it stays put.
@test "no command sends the test run itself to another container" {
    for command in "${ADDON_DIR}"/commands/web/*; do
        run grep -n 'exec -s playwright' "${command}"
        [ "$status" -ne 0 ]
    done
}
