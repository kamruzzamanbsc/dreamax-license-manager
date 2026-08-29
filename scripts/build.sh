#!/usr/bin/env bash
set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
commit="${1:-}"

if [[ -z "${commit}" ]]; then
  printf '%s\n' 'Usage: scripts/build.sh <recorded-git-commit>' >&2
  exit 2
fi

php "${project_dir}/scripts/build-release.php" --commit="${commit}" --output="${project_dir}/build"
