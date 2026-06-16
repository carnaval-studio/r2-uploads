<?php
/**
 * WordPress test configuration for local test runs.
 *
 * Copy this file to tests/wp-tests-config.php and update the database
 * credentials to point to a dedicated test database.
 */

// Path to a working WordPress installation. For a plugin in wp-content/plugins/r2-uploads,
// this is typically four directories up (wp-content -> site root).
define( 'ABSPATH', dirname( __FILE__, 4 ) . '/' );

define( 'DB_NAME', 'wordpress_unit_tests' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_DEBUG', true );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );

define( 'WP_PHP_BINARY', 'php' );

define( 'WPLANG', '' );

// Optional: point to a local MinIO instance for object-storage integration tests.
// define( 'R2_UPLOADS_TEST_ENDPOINT', 'http://127.0.0.1:9000' );
