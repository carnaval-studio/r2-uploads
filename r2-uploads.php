<?php

/**
 * Plugin Name: r2-uploads
 * Plugin URI: https://github.com/carnaval-studio/r2-uploads
 * Description: Store uploads in Cloudflare R2.
 * Version: 3.0.11
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

define( 'R2_UPLOADS_VERSION', '3.0.11' );
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
