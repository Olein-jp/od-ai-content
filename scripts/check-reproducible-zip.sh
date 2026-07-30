#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
temporary_dir="$(mktemp -d)"
trap 'rm -rf "${temporary_dir}"' EXIT

"${project_root}/scripts/build-zip.sh" "${temporary_dir}/first.zip"
"${project_root}/scripts/build-zip.sh" "${temporary_dir}/second.zip"

if ! cmp -s "${temporary_dir}/first.zip" "${temporary_dir}/second.zip"; then
	printf 'Distribution archives differ for the same Git reference.\n' >&2
	exit 1
fi

printf 'Reproducibility check passed for %s.\n' "${OD_AI_CONTENT_ARCHIVE_REF:-HEAD}"
