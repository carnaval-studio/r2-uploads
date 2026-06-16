<?php
/**
 * Bootstrap the plugin unit testing environment.
 *
 * @package WordPress
 * @subpackage JSON API
*/

if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
}

$wp_phpunit_dir = '/wp-phpunit';

if ( ! is_dir( $wp_phpunit_dir ) ) {
	$wp_phpunit_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! is_dir( $wp_phpunit_dir ) ) {
	throw new RuntimeException( 'wp-phpunit not found. Install dev dependencies with composer install or mount /wp-phpunit.' );
}

require rtrim( $wp_phpunit_dir, '/' ) . '/includes/functions.php';

function _manually_load_plugin() {
	if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
		require dirname( __DIR__ ) . '/vendor/autoload.php';
	}
	require dirname( __DIR__ ) . '/r2-uploads.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );


$minio_endpoint = getenv( 'R2_UPLOADS_TEST_ENDPOINT' );
if ( ! $minio_endpoint ) {
	$minio_endpoint = getenv( 'DOCKER_CONTAINER' ) ? 'http://minio:9000' : 'http://127.0.0.1:9000';
}

tests_add_filter( 'r2_uploads_s3_client_params', function ( array $params ) use ( $minio_endpoint ) : array {
	$params['endpoint'] = $minio_endpoint;
	$params['use_path_style_endpoint'] = true;
	return $params;
} );

if ( getenv( 'R2_UPLOADS_BUCKET' ) ) {
	define( 'R2_UPLOADS_BUCKET', getenv( 'R2_UPLOADS_BUCKET' ) );
}

if ( getenv( 'R2_UPLOADS_ACCOUNT_ID' ) ) {
	define( 'R2_UPLOADS_ACCOUNT_ID', getenv( 'R2_UPLOADS_ACCOUNT_ID' ) );
} else {
	define( 'R2_UPLOADS_ACCOUNT_ID', 'test-account' );
}

if ( getenv( 'R2_UPLOADS_KEY' ) ) {
	define( 'R2_UPLOADS_KEY', getenv( 'R2_UPLOADS_KEY' ) );
}

if ( getenv( 'R2_UPLOADS_SECRET' ) ) {
	define( 'R2_UPLOADS_SECRET', getenv( 'R2_UPLOADS_SECRET' ) );
}

if ( getenv( 'R2_UPLOADS_REGION' ) ) {
	define( 'R2_UPLOADS_REGION', getenv( 'R2_UPLOADS_REGION' ) );
}

if ( ! defined( 'R2_UPLOADS_BUCKET' ) ) {
	define( 'R2_UPLOADS_BUCKET', 'hmn-uploads' );
}

if ( ! defined( 'R2_UPLOADS_KEY' ) ) {
	define( 'R2_UPLOADS_KEY', 'key' );
}

if ( ! defined( 'R2_UPLOADS_SECRET' ) ) {
	define( 'R2_UPLOADS_SECRET', 'secret' );
}

if ( ! defined( 'R2_UPLOADS_REGION' ) ) {
	define( 'R2_UPLOADS_REGION', 'auto' );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require rtrim( $wp_phpunit_dir, '/' ) . '/includes/bootstrap.php';
