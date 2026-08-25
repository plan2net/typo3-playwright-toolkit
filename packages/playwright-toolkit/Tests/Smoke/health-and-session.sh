#!/usr/bin/env bash
# Smoke test for playwright_toolkit: boots in Testing context, asserts
# /test-api/health is ok and /test-api/session returns a usable JWT.
#
# Usage (run inside the host TYPO3 project's DDEV web container):
#   BASE_URL=https://<project>-testing.ddev.site ./health-and-session.sh
#
# Requires a prepared test database template (`ddev playwright-prepare`), which
# also writes the API secret every endpoint needs.
set -euo pipefail

BASE_URL="${BASE_URL:?set BASE_URL to the Testing site, e.g. https://example-testing.ddev.site}"
TEST_ID="${TEST_ID:-SMOKE00000000AAA}"

# Every endpoint requires the secret; playwright:prepare wrote it here.
SECRET="${PLAYWRIGHT_TOOLKIT_SECRET:-$(cat /var/www/html/var/playwright/api-secret 2>/dev/null || true)}"
if [ -z "${SECRET}" ]; then
    echo "FAIL: no API secret. Run 'ddev playwright-prepare', or set PLAYWRIGHT_TOOLKIT_SECRET." >&2
    exit 1
fi
AUTH=(-H "X-Playwright-Toolkit-Secret: ${SECRET}" -H "X-Playwright-Test-Id: ${TEST_ID}")

echo "==> Health check"
HEALTH=$(curl -fsS "${AUTH[@]}" "${BASE_URL}/test-api/health")
echo "${HEALTH}"
echo "${HEALTH}" | grep -q '"ok":true' || { echo "FAIL: health not ok"; exit 1; }

echo "==> Session creation"
SESSION=$(curl -fsS -X POST "${AUTH[@]}" "${BASE_URL}/test-api/session")
echo "${SESSION}"
echo "${SESSION}" | grep -q '"success":true' || { echo "FAIL: session not created"; exit 1; }
echo "${SESSION}" | grep -q '"cookieValue":"' || { echo "FAIL: no JWT in cookieValue"; exit 1; }

echo "==> SMOKE PASSED"
