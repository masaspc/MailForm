#!/usr/bin/env bash
set -eu
filter=$(cd "$(dirname "$0")/../../deploy/almalinux10" && pwd)/restore-warnings.awk
allowed="Warning (Code 1287): '@@sql_notes' is deprecated and will be removed in a future release. Please use '@@note_verbosity' instead"
printf '%s\n' "$allowed" "$allowed" | awk -f "$filter"
for line in "Warning (Code 1265): Data truncated for column" "${allowed/@@sql_notes/@@another}" "${allowed/1287/9999}"; do
    if printf '%s\n' "$allowed" "$line" "$allowed" | awk -f "$filter"; then
        exit 1
    fi
done
