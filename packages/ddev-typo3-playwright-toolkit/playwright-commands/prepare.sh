#!/bin/bash
#ddev-generated

set -e

if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
    echo "Usage: ddev playwright prepare [--force]"
    echo ""
    echo "Builds the test database template. --force rebuilds an up-to-date template."
    exit 0
fi

project_root="${DDEV_APPROOT:-/var/www/html}"
if [ ! -x "${project_root}/vendor/bin/typo3" ]; then
    echo "[playwright] vendor/bin/typo3 not found. Run 'ddev composer install' first." >&2
    exit 1
fi

. "${PW_ADDON_CONFIG_DIR:-/mnt/ddev_config}/playwright-lib.sh"

# Explicit preparation ignores PW_SKIP_PREPARE.
playwright_run_prepare "${project_root}" "$@" || {
    echo "[playwright] Could not build the test database template." >&2
    exit 1
}

echo "[playwright] Test database template ready."
