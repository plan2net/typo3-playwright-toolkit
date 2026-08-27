#!/usr/bin/env bash
#
# Installs all three packages into a real TYPO3 project and drives definePair
# against it. CI runs this same script, so a failure can be debugged where it
# happened rather than by pushing commits at it.
#
# Usage: tests/e2e/run.sh [--keep]
#   --keep   leave the DDEV project running afterwards, to poke at it

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CONSUMER="${REPO_ROOT}/tests/e2e"/consumer
PROJECT=t3pw-e2e
# 13.4 and 14.3 only: the fixture renders through a site-level setup.typoscript,
# which SiteConfiguration first reads in 13.4.
TYPO3_VERSION="${PW_E2E_TYPO3:-14.3}"
KEEP=0
[ "${1:-}" = "--keep" ] && KEEP=1

say() { echo "[e2e] $*"; }

cleanup_project() {
    ddev delete -Oy "${PROJECT}" >/dev/null 2>&1 || true
}

# A killed run leaves the project registered and its containers up.
cleanup_project
# Everything below is generated. `ddev delete` does not touch the project
# directory, so without this a rerun installs on top of the previous run's state —
# which is how a stale composer path package survives and how the TYPO3 package
# artifact ends up disagreeing with what is on disk.
for generated in .artifacts vendor public var .test-state composer.lock \
    config/system/settings.php tests/playwright/node_modules tests/playwright/package-lock.json; do
    rm -rf "${CONSUMER:?}/${generated}"
done
mkdir -p "${CONSUMER}/.artifacts" "${CONSUMER}/.cache/composer" "${CONSUMER}/.cache/ms-playwright"

say 'staging the extension inside the mount'
# ddev composer runs in the container, where a path repository outside
# /var/www/html cannot resolve — so the package is copied in rather than referenced.
rsync -a --delete \
    --exclude vendor/ --exclude public/ --exclude var/ --exclude '.phpunit*' \
    --exclude composer.lock --exclude '.php-cs-fixer.cache' \
    "${REPO_ROOT}/packages/playwright-toolkit/" "${CONSUMER}/.artifacts/playwright-toolkit/"

say 'packing the npm toolkit'
cd "${REPO_ROOT}/packages/typo3-playwright-toolkit"
# Only when absent: installing here on a laptop would swap the container's rollup
# binaries for the host's and break the monorepo's own suite.
[ -d node_modules ] || npm ci --silent
# prepack builds dist/, so the tarball is what a consumer would really install.
npm pack --silent --pack-destination "${CONSUMER}/.artifacts" >/dev/null
TARBALL="$(basename "$(ls -t "${CONSUMER}/.artifacts"/*.tgz | head -1)")"

# The published package declares @playwright/test as a peer at ^1.56.0, so a fresh
# install could resolve a newer browser than the cache key covers. The lock file is
# the single source of both.
PLAYWRIGHT_VERSION="$(node -p "require('${REPO_ROOT}/packages/typo3-playwright-toolkit/package-lock.json').packages['node_modules/@playwright/test'].version")"
say "toolkit ${TARBALL}, @playwright/test ${PLAYWRIGHT_VERSION}"

cd "${CONSUMER}"

say 'starting the project'
ddev start -y

say 'installing the DDEV add-on'
# Host-side, so this one reads the repository path directly and needs no staging.
ddev add-on get "${REPO_ROOT}/packages/ddev-typo3-playwright-toolkit"
ddev restart -y

say "installing TYPO3 ${TYPO3_VERSION} and the extension"
# --with rather than a rewritten composer.json: the fixture declares the range it
# supports and the row picks one out of it, leaving the file untouched.
ddev composer update --no-interaction --no-progress \
    --with "typo3/cms-core:^${TYPO3_VERSION}" \
    --with "typo3/cms-backend:^${TYPO3_VERSION}" \
    --with "typo3/cms-frontend:^${TYPO3_VERSION}" \
    --with "typo3/cms-fluid-styled-content:^${TYPO3_VERSION}" \
    --with "typo3/cms-install:^${TYPO3_VERSION}"

# The ordinary hostname runs outside the Testing context against this database, so
# it needs a schema of its own — otherwise the off-origin check hits a 500 rather
# than the 404 the context gate answers with. No --create-site: the site
# configuration and the root page are the fixture's own.
# `typo3 setup` refuses a database that already has tables, and --force does not
# cover that check, so a rerun would stop here on the previous run's schema.
say 'emptying the main database'
ddev exec psql -U db -d db -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;'

say 'installing TYPO3 into the main database'
ddev exec vendor/bin/typo3 setup --no-interaction --force \
    --driver=postgres --host=db --port=5432 --dbname=db --username=db --password=db \
    --admin-username=e2eadmin --admin-user-password='Playwright!e2e-2026' \
    --admin-email=e2e@example.test --project-name='Playwright toolkit e2e' --server-type=other
ddev exec vendor/bin/typo3 cache:flush

say 'installing the Playwright side'
(
    cd tests/playwright
    ddev npm install --no-audit --no-fund --save-dev \
        "/var/www/html/.artifacts/${TARBALL}" "@playwright/test@${PLAYWRIGHT_VERSION}"
    ddev npx playwright install --with-deps chromium
)

say 'running the suite'
ddev playwright test --reporter=list

# The only check anywhere that globalTeardown drops what it reports dropping.
say 'checking that no test database survived'
if ddev exec --service db-test psql -U db -lqt | grep -qE 'db[A-Z0-9]{16}'; then
    echo "[e2e] teardown left test databases behind:" >&2
    ddev exec --service db-test psql -U db -lqt | grep -oE 'db[A-Z0-9]{16}' >&2
    exit 1
fi

ddev exec 'vendor/bin/typo3 --version' | head -1
say 'green'
[ "${KEEP}" = "1" ] || cleanup_project
