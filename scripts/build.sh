#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="r2-uploads"
BUILD_DIR="$ROOT_DIR/build"
DIST_DIR="$ROOT_DIR/dist"
PACKAGE_DIR="$BUILD_DIR/$PLUGIN_SLUG"
VERSION="$(php -r '$f=file_get_contents($argv[1]); preg_match("/Version:\\s*([^\\s]+)/", $f, $m); echo $m[1] ?? "0.0.0";' "$ROOT_DIR/r2-uploads.php")"
ZIP_FILE="$DIST_DIR/$PLUGIN_SLUG-$VERSION.zip"
LATEST_ZIP_FILE="$DIST_DIR/$PLUGIN_SLUG-latest.zip"
STABLE_ZIP_FILE="$DIST_DIR/$PLUGIN_SLUG.zip"

cd "$ROOT_DIR"

if ! command -v composer >/dev/null 2>&1; then
	printf 'Error: composer is required to build the release ZIP.\n' >&2
	exit 1
fi

printf 'Installing production dependencies...\n'
composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction

rm -rf "$BUILD_DIR" "$DIST_DIR"
mkdir -p "$PACKAGE_DIR" "$DIST_DIR"

rsync -a "$ROOT_DIR/" "$PACKAGE_DIR/" \
	--exclude='.git' \
	--exclude='.github' \
	--exclude='build' \
	--exclude='dist' \
	--exclude='node_modules' \
	--exclude='package.json' \
	--exclude='package-lock.json' \
	--exclude='scripts' \
	--exclude='.DS_Store' \
	--exclude='*.log' \
	--exclude='composer.lock' \
	--exclude='tests' \
	--exclude='phpunit.xml.dist' \
	--exclude='psalm.xml' \
	--exclude='psalm.xml.dist' \
	--exclude='.phpcs.xml.dist' \
	--exclude='psalm' \
	--exclude='.gitignore' \
	--exclude='.gitattributes'

(cd "$BUILD_DIR" && zip -qr "$ZIP_FILE" "$PLUGIN_SLUG")
cp "$ZIP_FILE" "$LATEST_ZIP_FILE"
cp "$ZIP_FILE" "$STABLE_ZIP_FILE"
shasum -a 256 "$ZIP_FILE" > "$ZIP_FILE.sha256"
shasum -a 256 "$LATEST_ZIP_FILE" > "$LATEST_ZIP_FILE.sha256"
shasum -a 256 "$STABLE_ZIP_FILE" > "$STABLE_ZIP_FILE.sha256"

printf 'Built %s\n' "$ZIP_FILE"
printf 'Built %s\n' "$LATEST_ZIP_FILE"
printf 'Built %s\n' "$STABLE_ZIP_FILE"
