#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
plugin_slug="od-ai-content"
archive_path="${1:-${project_root}/build/${plugin_slug}.zip}"
main_plugin="${plugin_slug}/od-ai-content.php"

if [[ ! -f "${archive_path}" ]]; then
	printf 'Distribution archive not found: %s\n' "${archive_path}" >&2
	exit 1
fi

archive_entries="$(unzip -Z1 "${archive_path}")"

if printf '%s\n' "${archive_entries}" | grep -Ev "^${plugin_slug}/" >/dev/null; then
	printf 'Distribution archive contains entries outside %s/.\n' "${plugin_slug}" >&2
	exit 1
fi

forbidden_pattern="^${plugin_slug}/(\\.github/|\\.editorconfig$|\\.gitattributes$|\\.gitignore$|\\.wp-env\\.json$|\\.wp-env\\.override\\.json$|build/|composer\\.json$|composer\\.lock$|node_modules/|package\\.json$|package-lock\\.json$|phpcs\\.xml\\.dist$|phpunit\\.xml\\.dist$|scripts/|tests/)"

if printf '%s\n' "${archive_entries}" | grep -E "${forbidden_pattern}" >/dev/null; then
	printf 'Distribution archive contains development-only files:\n' >&2
	printf '%s\n' "${archive_entries}" | grep -E "${forbidden_pattern}" >&2
	exit 1
fi

required_vendor_files=(
	"${plugin_slug}/vendor/autoload.php"
	"${plugin_slug}/vendor/inc2734/wp-github-plugin-updater/src/Bootstrap.php"
	"${plugin_slug}/vendor/erusev/parsedown/Parsedown.php"
)

required_translation_files=(
	"${plugin_slug}/languages/od-ai-content.pot"
	"${plugin_slug}/languages/od-ai-content-ja.po"
	"${plugin_slug}/languages/od-ai-content-ja.mo"
)

for required_vendor_file in "${required_vendor_files[@]}"; do
	if ! printf '%s\n' "${archive_entries}" | grep -Fx "${required_vendor_file}" >/dev/null; then
		printf 'Required production dependency is missing: %s\n' "${required_vendor_file}" >&2
		exit 1
	fi
done

for required_translation_file in "${required_translation_files[@]}"; do
	if ! printf '%s\n' "${archive_entries}" | grep -Fx "${required_translation_file}" >/dev/null; then
		printf 'Required translation file is missing: %s\n' "${required_translation_file}" >&2
		exit 1
	fi
done

unexpected_vendor_entries="$(
	printf '%s\n' "${archive_entries}" |
		grep "^${plugin_slug}/vendor/" |
		grep -Ev "^${plugin_slug}/vendor/($|autoload\\.php$|composer/|inc2734/|erusev/)" ||
		true
)"

if [[ -n "${unexpected_vendor_entries}" ]]; then
	printf 'Distribution archive contains unexpected Composer dependencies:\n' >&2
	printf '%s\n' "${unexpected_vendor_entries}" >&2
	exit 1
fi

if ! printf '%s\n' "${archive_entries}" | grep -Fx "${main_plugin}" >/dev/null; then
	printf 'Main plugin file is missing: %s\n' "${main_plugin}" >&2
	exit 1
fi

plugin_headers="$(unzip -p "${archive_path}" "${main_plugin}")"

required_headers=(
	'Plugin Name:[[:space:]]+OD AI Content'
	'Requires at least:[[:space:]]+6\.9'
	'Requires PHP:[[:space:]]+7\.4'
	'Update URI:[[:space:]]+https://github\.com/Olein-jp/od-ai-content'
	'Text Domain:[[:space:]]+od-ai-content'
	'Domain Path:[[:space:]]+/languages'
)

for required_header in "${required_headers[@]}"; do
	if ! printf '%s\n' "${plugin_headers}" | grep -Eq "${required_header}"; then
		printf 'Required plugin header is missing or invalid: %s\n' "${required_header}" >&2
		exit 1
	fi
done

header_version="$(
	printf '%s\n' "${plugin_headers}" |
		sed -nE 's/^[[:space:]]*\*[[:space:]]+Version:[[:space:]]+([^[:space:]]+).*/\1/p'
)"
constant_version="$(
	printf '%s\n' "${plugin_headers}" |
		sed -nE "s/^define\\( 'OD_AI_CONTENT_VERSION', '([^']+)' \\);/\\1/p"
)"

if [[ ! "${header_version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
	printf 'Plugin header contains an invalid version: %s\n' "${header_version:-missing}" >&2
	exit 1
fi

if [[ "${header_version}" != "${constant_version}" ]]; then
	printf 'Plugin header version (%s) does not match OD_AI_CONTENT_VERSION (%s).\n' \
		"${header_version}" \
		"${constant_version:-missing}" >&2
	exit 1
fi

printf 'Verified %s (%s files).\n' \
	"${archive_path}" \
	"$(printf '%s\n' "${archive_entries}" | grep -c .)"
