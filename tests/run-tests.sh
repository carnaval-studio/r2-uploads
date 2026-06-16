#!/usr/bin/env bash
# tests/run-tests.sh - Run the full test suite.
#
# By default this script uses Docker to spin up MinIO and the WordPress test
# environment. If Docker is not available, it falls back to the local runner
# (tests/run-tests-local.sh) which requires a configured wp-tests-config.php.

set -e

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ "${NO_DOCKER:-}" == "1" ]] || ! command -v docker >/dev/null 2>&1; then
	if [[ "${NO_DOCKER:-}" == "1" ]]; then
		printf 'NO_DOCKER=1 set; running tests locally.\n'
	else
		printf 'Docker not found; falling back to local test runner.\n'
	fi
	exec "$ROOT_DIR/tests/run-tests-local.sh" "$@"
fi

if [ -d "/tmp/r2-uploads-tests" ]; then
	rm -rf /tmp/r2-uploads-tests/*
else
	mkdir /tmp/r2-uploads-tests
fi

docker run --rm --name r2-uploads-tests-minio -d -p 9000:9000 --tmpfs /data -e MINIO_ROOT_USER=AWSACCESSKEY -e MINIO_ROOT_PASSWORD=AWSSECRETKEY minio/minio:RELEASE.2025-01-20T14-49-07Z server /data > /dev/null

# Wait for Minio to be ready, then create the 'tests' bucket
echo "Waiting for Minio to start..."
for i in $(seq 1 30); do
	if docker run --rm --link r2-uploads-tests-minio:minio --entrypoint=/bin/sh minio/minio:RELEASE.2025-01-20T14-49-07Z -c "mc alias set myminio http://minio:9000 AWSACCESSKEY AWSSECRETKEY && mc mb --ignore-existing myminio/tests" > /dev/null 2>&1; then
		echo "Minio ready, 'tests' bucket created."
		break
	fi
	sleep 1
done

docker run --rm -e AWS_SUPPRESS_PHP_DEPRECATION_WARNING=1 -e R2_UPLOADS_BUCKET=tests -e R2_UPLOADS_ACCOUNT_ID=test-account -e R2_UPLOADS_KEY=AWSACCESSKEY -e R2_UPLOADS_SECRET=AWSSECRETKEY -e R2_UPLOADS_REGION=auto --link r2-uploads-tests-minio:minio -v $PWD:/code --entrypoint=/bin/sh humanmade/plugin-tester:wp-6.8-php8.3 -c "apk add --no-cache libjpeg-turbo ghostscript imagemagick-pdf > /dev/null 2>&1 && /entrypoint.sh $*"
docker kill r2-uploads-tests-minio > /dev/null

echo "Running Psalm..."
docker run --rm -v $PWD:/code --entrypoint=/bin/sh humanmade/plugin-tester:wp-6.8-php8.3 -c "apk add --no-cache php83-phar > /dev/null 2>&1 && /code/vendor/bin/psalm"
