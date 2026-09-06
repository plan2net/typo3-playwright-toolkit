#!/bin/bash
#ddev-generated

if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
    echo "Usage: ddev playwright clean"
    echo ""
    echo "Drops the test databases and run state a stopped run left behind, instead"
    echo "of waiting a day for the next run to reclaim them. A run that is still"
    echo "going keeps its databases, and so does the template."
    echo ""
    echo "Databases kept for 'ddev playwright inspect' go too, so look at them first."
    echo ""
    echo "Env (set these in web_environment or .ddev/.env.web to reach this command):"
    echo "  PW_TEST_DIR         Where your Playwright tests live, default tests/playwright"
    exit 0
fi

. "${PW_ADDON_CONFIG_DIR:-/mnt/ddev_config}/playwright-lib.sh" || exit 1
playwright_enter_test_dir || exit 1

exec npx typo3-playwright-clean "$@"
