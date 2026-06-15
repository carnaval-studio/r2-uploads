<?php

/*
Plugin Name: r2-uploads
Description: Store uploads in Cloudflare R2
Author: Human Made Limited
Version: 3.0.11
Author URI: https://hmn.md
*/

require_once __DIR__ . '/inc/namespace.php';

add_action( 'plugins_loaded', 'R2_Uploads\\init', 0, 0 );
