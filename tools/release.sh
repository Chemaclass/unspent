#!/usr/bin/env bash
#
# Cut a release.
#
# Runs the quality gates, promotes the CHANGELOG [Unreleased] section to the
# new version, commits, creates a signed tag, and pushes. The pushed tag
# triggers .github/workflows/release.yml, which publishes the GitHub Release
# using the notes extracted from CHANGELOG.md.
#
# Usage:
#   tools/release.sh <version>        e.g. tools/release.sh 1.2.0
#
# Preconditions: clean tree, on main, tag does not exist, [Unreleased] is
# non-empty, and `composer test` passes. Tag signing uses your configured
# git user.signingkey (git tag -s).
set -euo pipefail

version="${1:?usage: tools/release.sh <version>  (e.g. 1.2.0)}"
version="${version#v}"
tag="v${version}"
changelog="CHANGELOG.md"
today="$(date +%Y-%m-%d)"

repo_root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$repo_root"

# --- Pre-flight ----------------------------------------------------------
if [ -n "$(git status --porcelain)" ]; then
    echo "error: working tree is not clean; commit or stash first" >&2
    exit 1
fi

branch="$(git rev-parse --abbrev-ref HEAD)"
if [ "$branch" != "main" ]; then
    echo "error: releases must be cut from 'main' (currently on '$branch')" >&2
    exit 1
fi

if git rev-parse -q --verify "refs/tags/${tag}" >/dev/null; then
    echo "error: tag ${tag} already exists" >&2
    exit 1
fi

if ! grep -q '^## \[Unreleased\]' "$changelog"; then
    echo "error: no [Unreleased] section in ${changelog}" >&2
    exit 1
fi

unreleased_lines="$(
    awk '
        /^## \[Unreleased\]/ { collecting = 1; next }
        collecting && /^## \[/ { exit }
        collecting && NF { print }
    ' "$changelog" | wc -l | tr -d ' '
)"
if [ "$unreleased_lines" -eq 0 ]; then
    echo "error: [Unreleased] is empty — nothing to release" >&2
    exit 1
fi

# --- Quality gates -------------------------------------------------------
echo "==> Running quality gates (composer test)"
composer test

# --- Promote [Unreleased] -> [version] - date ----------------------------
echo "==> Updating ${changelog}: [Unreleased] -> [${version}] - ${today}"
awk -v ver="$version" -v date="$today" '
    /^## \[Unreleased\]/ {
        print "## [Unreleased]"
        print ""
        print "## [" ver "] - " date
        next
    }
    { print }
' "$changelog" > "${changelog}.tmp"
mv "${changelog}.tmp" "$changelog"

# --- Commit, sign-tag, push ---------------------------------------------
echo "==> Committing and tagging ${tag}"
git add "$changelog"
git commit -m "docs(changelog): release ${version}"
git tag -s "$tag" -m "Release ${version}"

git push origin "$branch"
git push origin "$tag"

echo ""
echo "==> Pushed ${tag}. The Release workflow will publish the GitHub Release."
echo "    Follow it:  gh run watch"
echo "    Then check: gh release view ${tag}"
