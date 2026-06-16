#!/usr/bin/env bash
# scripts/release.sh - Release r2-uploads from the master branch.
#
# Usage: scripts/release.sh [patch|minor|major|x.y.z]
#   Defaults to "patch" (auto-increment the patch number from r2-uploads.php).
#
# Steps:
#   1. Bump Version header and R2_UPLOADS_VERSION in r2-uploads.php
#   2. Update version in package.json
#   3. Run scripts/build.sh
#   4. Commit "Release <version>"
#   5. Push master
#   6. Create a GitHub release and upload the ZIP asset

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_FILE="$ROOT_DIR/r2-uploads.php"
PACKAGE_JSON="$ROOT_DIR/package.json"
BUMP="${1:-patch}"

cd "$ROOT_DIR"

if [[ -n "$(git status --porcelain)" ]]; then
	printf 'Error: working tree is not clean. Commit or stash your changes first.\n' >&2
	git status --short
	exit 1
fi

if ! command -v gh >/dev/null 2>&1; then
	printf 'Error: GitHub CLI (gh) is required to create releases.\n' >&2
	exit 1
fi

CURRENT_VERSION="$(php -r '$f=file_get_contents($argv[1]); preg_match("/Version:\\s*([^\\s]+)/", $f, $m); echo $m[1] ?? "";' "$PLUGIN_FILE")"
if [[ ! "$CURRENT_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	printf 'Error: could not read a semantic Version header from r2-uploads.php.\n' >&2
	exit 1
fi

IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT_VERSION"

case "$BUMP" in
	patch)
		PATCH=$((PATCH + 1))
		;;
	minor)
		MINOR=$((MINOR + 1))
		PATCH=0
		;;
	major)
		MAJOR=$((MAJOR + 1))
		MINOR=0
		PATCH=0
		;;
	[0-9]*.[0-9]*.[0-9]*)
		NEW_VERSION="$BUMP"
		;;
	*)
		printf 'Usage: scripts/release.sh [patch|minor|major|x.y.z]\n' >&2
		exit 1
		;;
esac

NEW_VERSION="${NEW_VERSION:-$MAJOR.$MINOR.$PATCH}"

if [[ ! "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	printf 'Error: release version must use x.y.z format.\n' >&2
	exit 1
fi

if ! php -r 'exit(version_compare($argv[2], $argv[1], ">") ? 0 : 1);' "$CURRENT_VERSION" "$NEW_VERSION"; then
	printf 'Error: release version %s must be greater than current version %s.\n' "$NEW_VERSION" "$CURRENT_VERSION" >&2
	exit 1
fi

BRANCH=$(git branch --show-current)
[[ "$BRANCH" == "master" ]] || { printf 'Error: run releases from master (current: %s).\n' "$BRANCH" >&2; exit 1; }

php -r '
$file = $argv[1];
$version = $argv[2];
$contents = file_get_contents($file);
$contents = preg_replace_callback(
	"/^( \\* Version: )[^\\s]+/m",
	static fn(array $matches): string => $matches[1] . $version,
	$contents,
	1
);
$contents = preg_replace(
	"/define\\(\\x27R2_UPLOADS_VERSION\\x27, \\x27[^\\x27]+\\x27\\);/",
	"define(" . chr(39) . "R2_UPLOADS_VERSION" . chr(39) . ", " . chr(39) . $version . chr(39) . ");",
	$contents,
	1
);
file_put_contents($file, $contents);
' "$PLUGIN_FILE" "$NEW_VERSION"

if [[ -f "$PACKAGE_JSON" ]]; then
	php -r '
$file = $argv[1];
$version = $argv[2];
$data = json_decode(file_get_contents($file), true);
if (!is_array($data)) {
	fwrite(STDERR, "Error: could not parse package.json.\n");
	exit(1);
}
$data["version"] = $version;
file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "$PACKAGE_JSON" "$NEW_VERSION"
fi

php -l "$PLUGIN_FILE" >/dev/null
bash "$ROOT_DIR/scripts/build.sh"

ZIP_FILE="$ROOT_DIR/dist/r2-uploads-$NEW_VERSION.zip"
STABLE_ZIP_FILE="$ROOT_DIR/dist/r2-uploads.zip"
if [[ ! -f "$ZIP_FILE" ]]; then
	printf 'Error: expected ZIP file not found: %s\n' "$ZIP_FILE" >&2
	exit 1
fi

if [[ ! -f "$STABLE_ZIP_FILE" ]]; then
	printf 'Error: expected stable ZIP file not found: %s\n' "$STABLE_ZIP_FILE" >&2
	exit 1
fi

git add "$PLUGIN_FILE"
if [[ -f "$PACKAGE_JSON" ]]; then
	git add "$PACKAGE_JSON"
fi
git commit -m "Release $NEW_VERSION"
git push origin HEAD

printf 'Creating GitHub release %s...\n' "$NEW_VERSION"
if ! gh release create "v$NEW_VERSION" \
	--title "v$NEW_VERSION" \
	--notes "Release v$NEW_VERSION" \
	"$ZIP_FILE" \
	"$STABLE_ZIP_FILE"; then
	printf 'Error: failed to create GitHub release.\n' >&2
	exit 1
fi

printf 'Released r2-uploads %s to GitHub.\n' "$NEW_VERSION"
printf 'Release asset: %s\n' "$ZIP_FILE"
