#!/bin/bash
#
# Stands in for the Playwright commands that serve something, announcing and exiting
# rather than blocking. Both lines are Playwright's own: `Serving HTML report at`
# from the HTML reporter, `Listening on` from openTraceInBrowser, which UI mode and
# the trace viewer share.

port=""
ui=""
while [ $# -gt 0 ]; do
    case "$1" in
        --port)
            port="$2"
            shift
            ;;
        --ui-port=*)
            port="${1#*=}"
            ui="yes"
            ;;
    esac
    shift
done

if [ -n "${ui}" ]; then
    echo "Listening on http://0.0.0.0:${port}"
else
    echo "Serving HTML report at http://0.0.0.0:${port:-9323}. Press Ctrl+C to quit."
fi
