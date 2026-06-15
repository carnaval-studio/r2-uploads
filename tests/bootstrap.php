<?php
/**
 * Bootstrap the plugin unit testing environment.
 *
 * @package WordPress
 * @subpackage JSON API
*/

require '/wp-phpunit/includes/functions.php';

function _manually_load_plugin() {
	if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
		require dirname( __DIR__ ) . '/vendor/autoload.php';
	}
	require dirname( __DIR__ ) . '/r2-uploads.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );


tests_add_filter( 'r2_uploads_s3_client_params', function ( array $params ) : array {
	$params['endpoint'] = 'http://minio:9000';
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

require '/wp-phpunit/includes/bootstrap.php';
