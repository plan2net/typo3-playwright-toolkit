#!/bin/bash
#
# Stands in for `npx playwright show-report` with no report on disk. The message is
# Playwright's own, the exit status is what the command under test reads.

echo 'No report found at "/var/www/html/tests/playwright/playwright-report"' >&2
exit 1
