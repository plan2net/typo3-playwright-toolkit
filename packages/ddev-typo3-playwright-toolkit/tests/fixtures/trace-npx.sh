#!/bin/bash

printf '%s\n' "$@" > "${TRACE_CALLS}"
if [ -n "${TRACE_PLAYWRIGHT_CLI:-}" ]; then
    shift
    exec node "${TRACE_PLAYWRIGHT_CLI}" "$@"
fi
exit "${TRACE_EXIT:-0}"
