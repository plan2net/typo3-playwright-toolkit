#!/bin/bash
#ddev-generated

if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
    echo "Usage: ddev playwright doctor [--project name] [--config file]"
    echo ""
    echo "Checks the browsers, the testing URL, API access and a temporary test"
    echo "database. Runs no tests, builds or repairs, and drops the database it made."
    echo ""
    echo "For files, versions and services, run 'ddev playwright setup' instead."
    echo ""
    echo "  --project name      Check one project; wildcards work, repeat for more"
    echo "  --config file       Use another Playwright config"
    echo ""
    echo "Env (set these in web_environment or .ddev/.env.web to reach this command):"
    echo "  PW_TEST_DIR         Where your Playwright tests live, default tests/playwright"
    exit 0
fi

. "${PW_ADDON_CONFIG_DIR:-/mnt/ddev_config}/playwright-lib.sh" || exit 1
playwright_enter_test_dir || exit 1

exec npx typo3-playwright-doctor "$@"
