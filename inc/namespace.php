<?php

namespace R2_Uploads;

function init() : void {
	// Ensure the AWS SDK can be loaded.
	if ( ! class_exists( '\\Aws\\S3\\S3Client' ) ) {
		trigger_error( 'R2 Uploads requires the AWS SDK. Ensure Composer dependencies have been loaded.', E_USER_WARNING );
		return;
	}

	if ( ! check_requirements() ) {
		return;
	}

	if ( ! defined( 'R2_UPLOADS_BUCKET' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\missing_config_notice' );
		return;
	}

	if ( ! defined( 'R2_UPLOADS_ACCOUNT_ID' ) || ! defined( 'R2_UPLOADS_KEY' ) || ! defined( 'R2_UPLOADS_SECRET' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\missing_config_notice' );
		return;
	}

	if ( ! enabled() ) {
		return;
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'r2-uploads', 'R2_Uploads\\WP_CLI_Command' );
	}

	$instance = Plugin::get_instance();
	$instance->setup();

	// Add filters to "wrap" the wp_privacy_personal_data_export_file function call as we need to
	// switch out the personal_data directory to a local temp folder, and then upload after it's
	// complete, as Core tries to write directly to the ZipArchive which won't work with the
	// R2 stream wrapper.
	add_action( 'wp_privacy_personal_data_export_file', __NAMESPACE__ . '\\before_export_personal_data', 9, 0 );
	add_action( 'wp_privacy_personal_data_export_file', __NAMESPACE__ . '\\after_export_personal_data', 11, 0 );
	add_action( 'wp_privacy_personal_data_export_file_created', __NAMESPACE__ . '\\move_temp_personal_data_to_s3', 1000 );
}

/**
 * Check whether the environment meets the plugin's requirements, like the minimum PHP version.
 *
 * @return bool True if the requirements are met, else false.
 */
function check_requirements() : bool {
	global $wp_version;

	if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			add_action( 'admin_notices', __NAMESPACE__ . '\\outdated_php_version_notice', 10, 0 );
		}

		return false;
	}

	if ( version_compare( $wp_version, '5.3.0', '<' ) ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			add_action( 'admin_notices', __NAMESPACE__ . '\\outdated_wp_version_notice', 10, 0 );
		}

		return false;
	}

	if ( ini_get( 'allow_url_fopen' ) === false || ini_get( 'allow_url_fopen' ) === '' ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			add_action( 'admin_notices', __NAMESPACE__ . '\\url_fopen_disabled_notice', 10, 0 );
		}

		return false;
	}

	return true;
}

/**
 * Print an admin notice when the PHP version is not high enough.
 *
 * This has to be a named function for compatibility with PHP 5.2.
 */
function outdated_php_version_notice() : void {
	printf(
		'<div class="error"><p>The R2 Uploads plugin requires PHP version 8.3 or higher. Your server is running PHP version %s.</p></div>',
		PHP_VERSION
	);
}

/**
 * Print an admin notice when required configuration constants are missing.
 */
function missing_config_notice() : void {
	printf(
		'<div class="error"><p>%s</p></div>',
		esc_html__( 'R2 Uploads is not active because required constants are missing. Define R2_UPLOADS_BUCKET, R2_UPLOADS_ACCOUNT_ID, R2_UPLOADS_KEY and R2_UPLOADS_SECRET in wp-config.php.', 'r2-uploads' )
	);
}

/**
 * Print an admin notice when the PHP version is not high enough.
 *
 * This has to be a named function for compatibility with PHP 5.2.
 */
function url_fopen_disabled_notice() : void {
	printf( '<div class="error"><p>The R2 Uploads plugin requires PHP option allow_url_fopen to be enabled. <a href="%s" target="_blank" rel="noopener noreferrer">Learn more</a>.</p></div>',
		'https://www.php.net/manual/en/filesystem.configuration.php#ini.allow-url-fopen'
	);
}

/**
 * Print an admin notice when the WP version is not high enough.
 *
 * This has to be a named function for compatibility with PHP 5.2.
 */
function outdated_wp_version_notice() : void {
	global $wp_version;

	printf(
		'<div class="error"><p>The R2 Uploads plugin requires WordPress version 5.3 or higher. Your server is running WordPress version %s.</p></div>',
		esc_html( $wp_version )
	);
}

/**
 * Check if URL rewriting is enabled.
 *
 * Define R2_UPLOADS_AUTOENABLE to false in your wp-config to disable, or use the
 * r2_uploads_enabled option.
 *
 * @return bool
 */
function enabled() : bool {
	// Make sure the plugin is enabled when autoenable is on
	$constant_autoenable_off = ( defined( 'R2_UPLOADS_AUTOENABLE' ) && false === R2_UPLOADS_AUTOENABLE );

	if ( $constant_autoenable_off && 'enabled' !== get_option( 'r2_uploads_enabled' ) ) { // If the plugin is not enabled, skip
		return false;
	}

	return true;
}

/**
 * Setup the filters for wp_privacy_exports_dir to use a temp folder location.
 */
function before_export_personal_data() : void {
	add_filter( 'wp_privacy_exports_dir', __NAMESPACE__ . '\\set_wp_privacy_exports_dir' );
}

/**
 * Remove the filters for wp_privacy_exports_dir as we only want it added in some cases.
 */
function after_export_personal_data() : void {
	remove_filter( 'wp_privacy_exports_dir', __NAMESPACE__ . '\\set_wp_privacy_exports_dir' );
}

/**
 * Override the wp_privacy_exports_dir location
 *
 * We don't want to use the default uploads folder location, as with S3 Uploads this is
 * going to the a s3:// custom URL handler, which is going to fail with the use of ZipArchive.
 * Instead we set to to WP's get_temp_dir and move the fail in the wp_privacy_personal_data_export_file_created
 * hook.
 *
 * @param string $dir
 * @return string
 */
function set_wp_privacy_exports_dir( string $dir ) {
	if ( strpos( $dir, 's3://' ) !== 0 ) {
		return $dir;
	}
	$dir = get_temp_dir() . 'wp_privacy_exports_dir/';
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir );
		file_put_contents( $dir . 'index.html', '' ); // @codingStandardsIgnoreLine FS write is ok.
	}
	return $dir;
}

/**
 * Move the tmp personal data file to the true uploads location
 *
 * Once a personal data file has been written, move it from the overridden "temp"
 * location to the S3 location where it should have been stored all along, and where
 * the "natural" Core URL is going to be pointing to.
 */
function move_temp_personal_data_to_s3( string $archive_pathname ) : void {
	if ( strpos( $archive_pathname, get_temp_dir() ) !== 0 ) {
		return;
	}
	$upload_dir = wp_upload_dir();
	$exports_dir = trailingslashit( $upload_dir['basedir'] ) . 'wp-personal-data-exports/';
	$destination = $exports_dir . pathinfo( $archive_pathname, PATHINFO_FILENAME ) . '.' . pathinfo( $archive_pathname, PATHINFO_EXTENSION );
	copy( $archive_pathname, $destination );
	unlink( $archive_pathname );
}
