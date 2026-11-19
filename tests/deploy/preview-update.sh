#!/usr/bin/env bash
set -euo pipefail
project=$(cd "$(dirname "$0")/../.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
git init -q "$tmp/repo"
checkout=(git -C "$tmp/repo")
commit() { git -C "$tmp/repo" -c user.name=Tests -c user.email=fixture@example.test commit -q --allow-empty -m "$1"; }
commit old
base_commit=$("${checkout[@]}" rev-parse HEAD)
commit candidate
candidate=$("${checkout[@]}" rev-parse HEAD)
"${checkout[@]}" switch -qc alternate "$base_commit"
commit divergent
divergent=$("${checkout[@]}" rev-parse HEAD)
# shellcheck source=../../deploy/almalinux10/setup-update.sh
source "$project/deploy/almalinux10/setup-update.sh"
preview_descendant "$base_commit" "$candidate"
if preview_descendant "$candidate" "$base_commit"; then exit 1; fi
if preview_descendant "$candidate" "$divergent"; then exit 1; fi
if preview_descendant "$base_commit" 'HEAD~1'; then exit 1; fi
echo 'Rehearsal revision ancestry checks passed.'
