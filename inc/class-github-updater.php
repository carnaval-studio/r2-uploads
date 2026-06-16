<?php

namespace R2_Uploads;

/**
 * GitHub release-based plugin updater.
 *
 * Uses the public GitHub releases API to detect new versions and serves the
 * release ZIP asset as the update package. Works without authentication for
 * public repositories; an optional token can be provided to raise API rate limits.
 */
final class GitHub_Updater {

	private string $file;
	private string $plugin_file;
	private string $plugin_dir;
	private string $plugin_slug;
	private string $plugin_version;
	private string $plugin_url;
	private string $update_uri;
	private string $github_owner;
	private string $github_repo;
	private string $github_token;

	public function __construct( string $file, string $token = '' ) {
		$resolved = realpath( $file );
		$this->file = $resolved !== false ? $resolved : $file;
		$this->github_token = $token;
		$this->plugin_file = plugin_basename( $this->file );
		$this->plugin_dir = dirname( $this->plugin_file );

		$data = get_file_data( $this->file, [
			'PluginURI' => 'Plugin URI',
			'Version' => 'Version',
			'UpdateURI' => 'Update URI',
		] );

		$this->plugin_version = (string) ( $data['Version'] ?? '' );
		$this->plugin_url = (string) ( $data['PluginURI'] ?? '' );
		$this->update_uri = (string) ( $data['UpdateURI'] ?? '' );

		$path = trim( (string) wp_parse_url( $this->update_uri, PHP_URL_PATH ), '/' );
		[ $owner, $repo ] = array_pad( explode( '/', $path, 2 ), 2, '' );
		$this->github_owner = $owner;
		$this->github_repo = $repo;
		$this->plugin_slug = $owner !== '' && $repo !== '' ? $owner . '-' . $repo : 'r2-uploads';
	}

	public function add_hooks() : void {
		if ( ! $this->is_configured() ) {
			add_action( 'admin_notices', [ $this, 'render_missing_config_notice' ] );
			return;
		}

		add_filter( 'update_plugins_github.com', [ $this, 'check_update' ], 10, 3 );
		add_filter( 'plugins_api', [ $this, 'plugin_information' ], 10, 3 );
		add_filter( 'http_request_args', [ $this, 'add_github_auth_header' ], 10, 2 );
		add_filter( 'upgrader_install_package_result', [ $this, 'normalize_installed_directory' ], 10, 2 );
	}

	/**
	 * @param array<string,mixed>|false $update
	 * @param array<string,mixed>       $data
	 * @return array<string,mixed>|false
	 */
	public function check_update( $update, array $data, string $file ) {
		if ( $file !== $this->plugin_file || (string) ( $data['UpdateURI'] ?? '' ) !== $this->update_uri ) {
			return $update;
		}

		$release = $this->get_latest_release();
		if ( $release === null ) {
			return $update;
		}

		$remote_version = $release['version'];
		if ( $remote_version === '' || ! version_compare( $remote_version, $this->plugin_version, '>' ) ) {
			return $update;
		}

		return [
			'id' => $this->update_uri,
			'slug' => $this->plugin_slug,
			'plugin' => $this->plugin_file,
			'version' => $remote_version,
			'new_version' => $remote_version,
			'url' => $this->plugin_url,
			'package' => $release['package'],
			'tested' => $this->get_tested_wp_version(),
			'requires' => '5.3',
			'requires_php' => '8.0',
		];
	}

	/**
	 * @param object|bool|null $result
	 * @return object|bool|null
	 */
	public function plugin_information( $result, string $action, object $args ) {
		if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== $this->plugin_slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( $release === null ) {
			return $result;
		}

		return (object) [
			'name' => 'R2 Uploads',
			'slug' => $this->plugin_slug,
			'version' => $release['version'],
			'author' => 'Carnaval Studio',
			'homepage' => $this->plugin_url,
			'requires' => '5.3',
			'requires_php' => '8.0',
			'tested' => $this->get_tested_wp_version(),
			'sections' => [
				'description' => 'WordPress plugin for storing uploads in Cloudflare R2.',
				'changelog' => 'See the GitHub repository for release notes and commit history.',
			],
			'download_link' => $release['package'],
		];
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function add_github_auth_header( array $args, string $url ) : array {
		if ( $this->github_token === '' ) {
			return $args;
		}

		$api_prefix = 'https://api.github.com/repos/' . $this->github_owner . '/' . $this->github_repo;
		if ( strpos( $url, $api_prefix ) !== 0 ) {
			return $args;
		}

		$args['headers']['Authorization'] = 'Bearer ' . $this->github_token;
		if ( ! isset( $args['headers']['Accept'] ) ) {
			$args['headers']['Accept'] = 'application/vnd.github+json';
		}

		return $args;
	}

	/**
	 * @param array<string,mixed>|WP_Error $result
	 * @param array<string,mixed>          $hook_extra
	 * @return array<string,mixed>|WP_Error
	 */
	public function normalize_installed_directory( $result, array $hook_extra ) {
		if ( is_wp_error( $result ) || ( $hook_extra['plugin'] ?? '' ) !== $this->plugin_file ) {
			return $result;
		}

		$destination = (string) ( $result['destination'] ?? '' );
		$local_destination = (string) ( $result['local_destination'] ?? WP_PLUGIN_DIR );
		if ( $destination === '' || $this->plugin_dir === '.' || strpos( basename( $destination ), $this->github_repo ) === false ) {
			return $result;
		}

		$target = trailingslashit( $local_destination ) . $this->plugin_dir;
		if ( ! function_exists( 'move_dir' ) ) {
			return $result;
		}

		// Avoid deleting the target if WordPress already extracted to the
		// correct directory (paths may differ only by trailing slash).
		if ( untrailingslashit( $destination ) === untrailingslashit( $target ) ) {
			$result['destination'] = $target;
			$result['destination_name'] = $this->plugin_dir;
			$result['remote_destination'] = $target;
			return $result;
		}

		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->exists( $target ) ) {
			$wp_filesystem->delete( $target, true );
		}

		$moved = move_dir( $destination, $target );
		if ( is_wp_error( $moved ) ) {
			return $result;
		}

		$result['destination'] = $target;
		$result['destination_name'] = $this->plugin_dir;
		$result['remote_destination'] = $target;

		return $result;
	}

	public function render_missing_config_notice() : void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html__( 'R2 Uploads GitHub updates are disabled because the plugin header is missing Plugin URI, Version, or Update URI.', 'r2-uploads' )
		);
	}

	private function is_configured() : bool {
		return $this->plugin_version !== ''
			&& $this->plugin_url !== ''
			&& $this->update_uri !== ''
			&& $this->github_owner !== ''
			&& $this->github_repo !== '';
	}

	/**
	 * @return array{version:string, package:string}|null
	 */
	private function get_latest_release() : ?array {
		$url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			rawurlencode( $this->github_owner ),
			rawurlencode( $this->github_repo )
		);

		$args = [ 'timeout' => 10 ];
		if ( $this->github_token !== '' ) {
			$args['headers'] = [
				'Authorization' => 'Bearer ' . $this->github_token,
				'Accept' => 'application/vnd.github+json',
			];
		}

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$release = json_decode( $body, true );
		if ( ! is_array( $release ) || ! isset( $release['tag_name'] ) ) {
			return null;
		}

		$version = ltrim( (string) $release['tag_name'], 'vV' );
		$package = $this->get_release_zip_url( $release );

		if ( $version === '' || $package === '' ) {
			return null;
		}

		return [
			'version' => $version,
			'package' => $package,
		];
	}

	/**
	 * @param array<string,mixed> $release
	 */
	private function get_release_zip_url( array $release ) : string {
		$expected_asset = 'r2-uploads-' . ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' ) . '.zip';
		$assets = $release['assets'] ?? [];

		if ( is_array( $assets ) ) {
			foreach ( $assets as $asset ) {
				if ( ! is_array( $asset ) ) {
					continue;
				}

				$name = (string) ( $asset['name'] ?? '' );
				$browser_url = (string) ( $asset['browser_download_url'] ?? '' );
				if ( $name === $expected_asset && $browser_url !== '' ) {
					return $browser_url;
				}
			}
		}

		// Fallback to the source archive if no matching release asset exists.
		$zip_url = (string) ( $release['zipball_url'] ?? '' );
		return $zip_url;
	}

	private function get_tested_wp_version() : string {
		global $wp_version;

		return is_string( $wp_version ) ? $wp_version : '';
	}
}
