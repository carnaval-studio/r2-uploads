#!/usr/bin/env bash
# tests/run-tests-local.sh - Run the test suite locally without Docker.
#
# Requirements:
#   - composer install (with dev dependencies)
#   - A MySQL/MariaDB server and a wp-tests-config.php file
#   - (Optional) A running MinIO or S3-compatible service for integration tests.
#
# The test database is reset on every run. Use a dedicated test database.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TESTS_DIR="$ROOT_DIR/tests"

cd "$ROOT_DIR"

if [[ ! -f "$ROOT_DIR/vendor/bin/phpunit" ]]; then
	printf 'Error: PHPUnit not found. Run: composer install\n' >&2
	exit 1
fi

if [[ ! -f "$ROOT_DIR/vendor/wp-phpunit/wp-phpunit/includes/bootstrap.php" ]]; then
	printf 'Error: wp-phpunit not found. Run: composer install\n' >&2
	exit 1
fi

if [[ ! -f "$TESTS_DIR/wp-tests-config.php" ]]; then
	printf 'Error: %s/wp-tests-config.php not found.\n' "$TESTS_DIR" >&2
	printf 'Copy tests/wp-tests-config-sample.php to tests/wp-tests-config.php and configure your test database.\n' >&2
	exit 1
fi

export AWS_SUPPRESS_PHP_DEPRECATION_WARNING=1

# Detect whether an S3-compatible endpoint is reachable for integration tests.
MINIO_ENDPOINT="${R2_UPLOADS_TEST_ENDPOINT:-http://127.0.0.1:9000}"
MINIO_URL="${MINIO_ENDPOINT}/minio/health/live"

if ! curl -sf --max-time 2 "$MINIO_URL" >/dev/null 2>&1; then
	printf 'Warning: no S3-compatible service found at %s.\n' "$MINIO_ENDPOINT" >&2
	printf 'Integration tests that use the stream wrapper will fail.\n' >&2
	printf 'Start MinIO locally or set R2_UPLOADS_TEST_ENDPOINT to a running service.\n' >&2
	printf '\nExample with Docker (for the test service only):\n' >&2
	printf '  docker run -d -p 9000:9000 --name r2-uploads-minio -e MINIO_ROOT_USER=AWSACCESSKEY -e MINIO_ROOT_PASSWORD=AWSSECRETKEY minio/minio server /data\n' >&2
fi
export R2_UPLOADS_BUCKET="${R2_UPLOADS_BUCKET:-tests}"
export R2_UPLOADS_ACCOUNT_ID="${R2_UPLOADS_ACCOUNT_ID:-test-account}"
export R2_UPLOADS_KEY="${R2_UPLOADS_KEY:-AWSACCESSKEY}"
export R2_UPLOADS_SECRET="${R2_UPLOADS_SECRET:-AWSSECRETKEY}"
export R2_UPLOADS_REGION="${R2_UPLOADS_REGION:-auto}"

if [[ -n "${MINIO_ENDPOINT:-}" ]]; then
	printf 'Using MinIO endpoint: %s\n' "$MINIO_ENDPOINT"
fi

# Create the test bucket if MinIO is running locally and the mc client is available.
if command -v mc >/dev/null 2>&1 && [[ -n "${MINIO_ENDPOINT:-}" ]]; then
	mc alias set r2uploads-local "$MINIO_ENDPOINT" "$R2_UPLOADS_KEY" "$R2_UPLOADS_SECRET" >/dev/null 2>&1 || true
	mc mb --ignore-existing "r2uploads-local/$R2_UPLOADS_BUCKET" >/dev/null 2>&1 || true
fi

exec "$ROOT_DIR/vendor/bin/phpunit" --configuration "$ROOT_DIR/phpunit.xml.dist" "$@"
