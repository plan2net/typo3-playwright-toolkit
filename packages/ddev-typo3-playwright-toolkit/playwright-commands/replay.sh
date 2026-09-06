#!/bin/bash
#ddev-generated

if [ "$1" = "--help" ] || [ "$1" = "-h" ]; then
    echo "Usage: ddev playwright replay [options]"
    echo ""
    echo "Rebuilds the replay database on the db-test service from the template, then"
    echo "runs every scenario's setup into it. Your project database is never touched."
    echo "Each scenario gets a folder named after it under the fixture root, holding"
    echo "everything that scenario creates."
    echo ""
    echo "The tests themselves are skipped: their assertions and screenshots belong"
    echo "to a per-test database. Use 'ddev playwright test' for those."
    echo ""
    echo "The run ends by printing a link that logs you into that database's backend."
    echo "What you get there is sample pages nobody had to build by hand: one example"
    echo "of every content element your builders know how to make."
    echo ""
    echo "Options this command handles itself:"
    echo "  --skip-build        Do not build frontend assets first"
    echo "  --skip-prepare      Reuse the existing test database template"
    echo ""
    echo "Everything else is forwarded to 'npx playwright test', so --grep and a path"
    echo "filter pick which scenarios replay. --workers is refused: every scenario"
    echo "shares one database."
    echo ""
    echo "Env (set these in web_environment or .ddev/.env.web to reach this command):"
    echo "  PW_TEST_DIR         Where your Playwright tests live, default tests/playwright"
    exit 0
fi

. "${PW_ADDON_CONFIG_DIR:-/mnt/ddev_config}/playwright-lib.sh" || exit 1

playwright_enter_test_dir || exit 1

playwright_collect_args "$@"

playwright_refuse_worker_override "${PW_ARGS[@]}" || exit 1

playwright_prepare_template "${DDEV_APPROOT:-/var/www/html}" || exit 1
playwright_replay_prepare "${DDEV_APPROOT:-/var/www/html}" || exit 1

export PW_REPLAY=1

exec npx playwright test --workers=1 "${PW_ARGS[@]}"
