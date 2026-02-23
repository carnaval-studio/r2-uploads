set -e

if [ -d "/tmp/s3-uploads-tests" ]; then
	rm -rf /tmp/s3-uploads-tests/*
else
	mkdir /tmp/s3-uploads-tests
fi

docker run --rm --name s3-uploads-tests-minio -d -p 9000:9000 -e MINIO_ROOT_USER=AWSACCESSKEY -e MINIO_ROOT_PASSWORD=AWSSECRETKEY minio/minio server /data > /dev/null

# Wait for Minio to be ready, then create the 'tests' bucket
echo "Waiting for Minio to start..."
for i in $(seq 1 30); do
	if docker run --rm --link s3-uploads-tests-minio:minio --entrypoint=/bin/sh minio/minio -c "mc alias set myminio http://minio:9000 AWSACCESSKEY AWSSECRETKEY && mc mb --ignore-existing myminio/tests" > /dev/null 2>&1; then
		echo "Minio ready, 'tests' bucket created."
		break
	fi
	sleep 1
done

docker run --rm -e AWS_SUPPRESS_PHP_DEPRECATION_WARNING=1 -e S3_UPLOADS_BUCKET=tests -e S3_UPLOADS_KEY=AWSACCESSKEY -e S3_UPLOADS_SECRET=AWSSECRETKEY -e S3_UPLOADS_REGION=us-east-1 --link s3-uploads-tests-minio:minio -v $PWD:/code humanmade/plugin-tester $@
docker kill s3-uploads-tests-minio > /dev/null

echo "Running Psalm..."
docker run --rm -v $PWD:/code -e TRAVIS=$TRAVIS -e TRAVIS_JOB_ID=$TRAVIS_JOB_ID -e TRAVIS_REPO_SLUG=$TRAVIS_REPO_SLUG -e TRAVIS_BRANCH=$TRAVIS_BRANCH --entrypoint='/code/vendor/bin/psalm' humanmade/plugin-tester --shepherd
