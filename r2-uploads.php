<?php

/**
 * Plugin Name: R2 Uploads
 * Plugin URI: https://github.com/carnaval-studio/r2-uploads
 * Description: Store uploads in Cloudflare R2.
 * Version: 3.0.14
 * Requires at least: 5.3
 * Requires PHP: 8.0
 * Author: Carnaval Studio
 * Author URI: https://github.com/carnaval-studio
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: r2-uploads
 * Update URI: https://github.com/carnaval-studio/r2-uploads
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define('R2_UPLOADS_VERSION', '3.0.14');
define( 'R2_UPLOADS_FILE', __FILE__ );
define( 'R2_UPLOADS_PATH', plugin_dir_path( __FILE__ ) );
define( 'R2_UPLOADS_URL', plugin_dir_url( __FILE__ ) );

$autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

require_once __DIR__ . '/inc/namespace.php';
require_once __DIR__ . '/inc/class-github-updater.php';

add_action( 'plugins_loaded', 'R2_Uploads\\init', 0, 0 );

// Enable GitHub release-based updates for public repositories.
add_action( 'plugins_loaded', static function () : void {
	$token = defined( 'R2_UPLOADS_GITHUB_TOKEN' ) && is_string( R2_UPLOADS_GITHUB_TOKEN )
		? R2_UPLOADS_GITHUB_TOKEN
		: '';

	( new R2_Uploads\GitHub_Updater( R2_UPLOADS_FILE, $token ) )->add_hooks();
}, 1, 0 );

// Add a "Check Update" link to the plugin row meta on the Plugins page.
add_filter(
	'plugin_row_meta',
	static function ( array $plugin_meta, string $plugin_file ) : array {
		if ( $plugin_file !== plugin_basename( R2_UPLOADS_FILE ) ) {
			return $plugin_meta;
		}

		$check_update_url = wp_nonce_url(
			add_query_arg( 'r2_uploads_check_update', '1', admin_url( 'plugins.php' ) ),
			'r2_uploads_check_update'
		);

		$plugin_meta[] = '<a href="' . esc_url( $check_update_url ) . '">' . esc_html__( 'Check Update', 'r2-uploads' ) . '</a>';

		return $plugin_meta;
	},
	10,
	2
);

// Force a plugin update check when the "Check Update" link is clicked.
add_action(
	'admin_init',
	static function () : void {
		if ( ! isset( $_GET['r2_uploads_check_update'] ) ) {
			return;
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$nonce = sanitize_text_field( (string) ( $_GET['_wpnonce'] ?? '' ) );
		if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'r2_uploads_check_update' ) ) {
			return;
		}

		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$redirect = remove_query_arg(
			[ 'r2_uploads_check_update', '_wpnonce', 'r2_uploads_update_result' ],
			wp_get_referer() ?: admin_url( 'plugins.php' )
		);
		$redirect = add_query_arg( 'r2_uploads_update_result', '1', $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}
);

// Show the result of a forced update check on the Plugins page.
add_action(
	'admin_notices',
	static function () : void {
		if ( ! isset( $_GET['r2_uploads_update_result'] ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'plugins' ) {
			return;
		}

		$transient = get_site_transient( 'update_plugins' );
		$latest = '';
		$plugin_basename = plugin_basename( R2_UPLOADS_FILE );

		if ( is_object( $transient ) && isset( $transient->response[ $plugin_basename ]->new_version ) ) {
			$latest = (string) $transient->response[ $plugin_basename ]->new_version;
		}

		if ( $latest !== '' ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( __( 'R2 Uploads %s is available. Use the "Update now" action to install it.', 'r2-uploads' ), $latest ) )
			);
		} else {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html__( 'R2 Uploads is up to date.', 'r2-uploads' )
			);
		}
	}
);
