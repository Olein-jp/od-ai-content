#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
plugin_slug="od-ai-content"
archive_ref="${OD_AI_CONTENT_ARCHIVE_REF:-HEAD}"
archive_path="${1:-${project_root}/build/${plugin_slug}.zip}"

if [[ "${archive_path}" != /* ]]; then
	archive_path="${project_root}/${archive_path}"
fi

archive_dir="$(dirname "${archive_path}")"

mkdir -p "${archive_dir}"

temporary_archive="$(mktemp "${archive_path}.tmp.XXXXXX")"
temporary_dir="$(mktemp -d)"
trap 'rm -f "${temporary_archive}"; rm -rf "${temporary_dir}"' EXIT

git -C "${project_root}" archive \
	--worktree-attributes \
	--format=zip \
	--prefix="${plugin_slug}/" \
	--output="${temporary_archive}" \
	"${archive_ref}"

dependency_dir="${temporary_dir}/dependencies"
vendor_stage="${temporary_dir}/archive/${plugin_slug}"

mkdir -p "${dependency_dir}" "${vendor_stage}"

git -C "${project_root}" show "${archive_ref}:composer.json" > "${dependency_dir}/composer.json"
git -C "${project_root}" show "${archive_ref}:composer.lock" > "${dependency_dir}/composer.lock"

composer install \
	--working-dir="${dependency_dir}" \
	--no-dev \
	--no-interaction \
	--no-progress \
	--no-scripts \
	--prefer-dist \
	--classmap-authoritative

cp -R "${dependency_dir}/vendor" "${vendor_stage}/vendor"

commit_timestamp="$(git -C "${project_root}" show -s --format=%ct "${archive_ref}")"
php "${project_root}/scripts/normalize-mtimes.php" \
	"${temporary_dir}/archive" \
	"${commit_timestamp}"

(
	cd "${temporary_dir}/archive"
	find "${plugin_slug}/vendor" -print |
		LC_ALL=C sort |
		zip -X -q "${temporary_archive}" -@
)

mv "${temporary_archive}" "${archive_path}"
trap - EXIT
rm -rf "${temporary_dir}"

printf 'Created %s from %s\n' "${archive_path}" "${archive_ref}"
