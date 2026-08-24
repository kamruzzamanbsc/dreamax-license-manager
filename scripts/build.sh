#!/usr/bin/env bash
set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
build_dir="${project_dir}/build"
source_epoch="${SOURCE_DATE_EPOCH:-1787529600}"
version="$(sed -n "s/^define( 'DREAMAX_LM_VERSION', '\([^']*\)' );/\1/p" "${project_dir}/dreamax-license-manager.php")"
artifact="dreamax-license-manager-${version}.zip"
temporary="$(mktemp -d)"
staged="${temporary}/dreamax-license-manager"

cleanup() {
  rm -rf "${temporary}"
}
trap cleanup EXIT

mkdir -p "${build_dir}" "${staged}"
(
  cd "${project_dir}"
  find . -type f \
    ! -path './build/*' \
    ! -path './tests/*' \
    ! -path './vendor/*' \
    ! -path './.git/*' \
    ! -name 'phpunit.xml.dist' \
    ! -name 'phpstan.neon' \
    ! -name 'phpcs.xml.dist' \
    -print0 | sort -z | while IFS= read -r -d '' file; do
      mkdir -p "${staged}/$(dirname "${file#./}")"
      cp "${file#./}" "${staged}/${file#./}"
    done
)

find "${staged}" -exec touch -h -d "@${source_epoch}" {} +
rm -f "${build_dir}/${artifact}"
(
  cd "${temporary}"
  find dreamax-license-manager -type f -print | LC_ALL=C sort | zip -X -q "${build_dir}/${artifact}" -@
)

zip_sha="$(sha256sum "${build_dir}/${artifact}" | awk '{print $1}')"
lock_sha="none"
if [[ -f "${project_dir}/composer.lock" ]]; then
  lock_sha="$(sha256sum "${project_dir}/composer.lock" | awk '{print $1}')"
fi
commit="uncommitted"
dirty="unknown"
if git -C "${project_dir}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  commit="$(git -C "${project_dir}" rev-parse HEAD 2>/dev/null || printf 'uncommitted')"
  dirty="$(git -C "${project_dir}" status --porcelain | test "$(wc -l)" -eq 0 && printf 'clean' || printf 'dirty')"
fi
build_time="$(date -u -d "@${source_epoch}" '+%Y-%m-%dT%H:%M:%SZ')"

manifest="${build_dir}/release-manifest.json"
printf '{\n  "plugin_name": "Dreamax License Manager",\n  "plugin_version": "%s",\n  "artifact_filename": "%s",\n  "source_commit": "%s",\n  "source_state": "%s",\n  "build_command": "SOURCE_DATE_EPOCH=%s composer build",\n  "build_profile": "wordpress-development-distribution",\n  "distribution_sha256": "%s",\n  "dependency_lock_sha256": "%s",\n  "dependency_inventory": "docs/DEPENDENCIES.md",\n  "tested_versions": {"wordpress": [], "woocommerce": [], "php": []},\n  "build_utc": "%s",\n  "release_gate_evidence": "docs/FREE-V1-MUST-PASS.md"\n}\n' \
  "${version}" "${artifact}" "${commit}" "${dirty}" "${source_epoch}" "${zip_sha}" "${lock_sha}" "${build_time}" > "${manifest}"

printf '%s\n%s\n' "${build_dir}/${artifact}" "${manifest}"
