#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
plugin_slug="od-ai-content"
archive_ref="${OD_AI_CONTENT_ARCHIVE_REF:-HEAD}"
archive_path="${1:-${project_root}/build/${plugin_slug}.zip}"
archive_dir="$(dirname "${archive_path}")"

mkdir -p "${archive_dir}"

temporary_archive="$(mktemp "${archive_path}.tmp.XXXXXX")"
trap 'rm -f "${temporary_archive}"' EXIT

git -C "${project_root}" archive \
	--worktree-attributes \
	--format=zip \
	--prefix="${plugin_slug}/" \
	--output="${temporary_archive}" \
	"${archive_ref}"

mv "${temporary_archive}" "${archive_path}"
trap - EXIT

printf 'Created %s from %s\n' "${archive_path}" "${archive_ref}"
