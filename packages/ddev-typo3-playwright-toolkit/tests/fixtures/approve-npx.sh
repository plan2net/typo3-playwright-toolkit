#!/bin/bash

printf '%s\n' "$@" > "${APPROVE_CALLS}"
if [ -n "${APPROVE_PREPARE_CALLS:-}" ]; then
    printf 'playwright\n' >> "${APPROVE_PREPARE_CALLS}"
fi
if [ -n "${APPROVE_PLAYWRIGHT_CLI:-}" ]; then
    shift
    exec node "${APPROVE_PLAYWRIGHT_CLI}" "$@"
fi
if [ -n "${PLAYWRIGHT_JSON_OUTPUT_FILE:-}" ]; then
    printf '{"config":{"projects":[{"name":"chromium","outputDir":"%s/test-results"}]}}' "${PW_TEST_DIR}" > "${PLAYWRIGHT_JSON_OUTPUT_FILE}"
fi
exit "${APPROVE_EXIT:-0}"
