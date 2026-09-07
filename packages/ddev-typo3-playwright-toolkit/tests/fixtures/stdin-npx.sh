#!/bin/bash
#
# Records whether it was handed a terminal on stdin. Playwright's closing hint, the
# one naming `npx playwright show-report`, prints only when stdin is a TTY.

if [ -t 0 ]; then
    echo "terminal" > "${TRACE_CALLS}"
else
    echo "not a terminal" > "${TRACE_CALLS}"
fi
