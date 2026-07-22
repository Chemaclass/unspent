#!/usr/bin/env bash
#
# Extract the release notes for a single version from CHANGELOG.md.
#
# Prints the body between "## [<version>]" and the next "## [" header, with
# leading/trailing blank lines and a trailing "---" separator stripped.
#
# Usage:
#   tools/changelog-extract.sh <version> [changelog-path]
#
# Examples:
#   tools/changelog-extract.sh 1.1.0
#   tools/changelog-extract.sh v1.1.0 CHANGELOG.md
#
# Exits non-zero if the version has no section (keeps releases and the
# CHANGELOG honest: you cannot cut a release the CHANGELOG does not describe).
set -euo pipefail

version="${1:?usage: changelog-extract.sh <version> [changelog-path]}"
changelog="${2:-CHANGELOG.md}"
version="${version#v}"

if [ ! -f "$changelog" ]; then
    echo "error: changelog not found: $changelog" >&2
    exit 1
fi

notes="$(
    awk -v ver="$version" '
        $0 ~ ("^## \\[" ver "\\]")   { collecting = 1; next }
        collecting && /^## \[/       { exit }
        collecting                   { buf[n++] = $0 }
        END {
            start = 0
            while (start < n && buf[start] ~ /^[[:space:]]*$/) start++
            end = n - 1
            while (end >= start && (buf[end] ~ /^[[:space:]]*$/ || buf[end] ~ /^-{3,}[[:space:]]*$/)) end--
            for (i = start; i <= end; i++) print buf[i]
        }
    ' "$changelog"
)"

if [ -z "$notes" ]; then
    echo "error: no CHANGELOG section found for version '$version' in $changelog" >&2
    exit 1
fi

printf '%s\n' "$notes"
