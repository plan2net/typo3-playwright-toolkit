#!/bin/bash

printf '%s %s\n' "${TYPO3_CONTEXT}" "$*" >> "${APPROVE_PREPARE_CALLS}"
exit "${APPROVE_PREPARE_EXIT:-0}"
