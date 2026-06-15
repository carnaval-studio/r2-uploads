<?php

namespace R2_Uploads;

use Aws;

/**
 * @psalm-consistent-constructor
 */
class Plugin {

	/**
	 * The S3 bucket with path.
	 *
	 * @var string
	 */
	private $bucket;

	/**
	 * The URL that resolves to the S3 bucket.
	 *
	 * @var ?string
	 */
	private $bucket_url;

	/**
	 * AWS IAM access key used for S3 Access.
	 *
	 * @var ?string
	 */
	private $key;

	/**
	 * AWS IAM access key secret used for S3 Access.
	 *
	 * @var ?string
	 */
	private $secret;

	/**
	 * Cloudflare account ID used to build the R2 S3-compatible endpoint.
	 *
	 * @var ?string
	 */
	private $account_id;

	/**
	 * Original wp_upload_dir() before being replaced by S3 Uploads.
	 *
	 * @var ?array{path: string, basedir: string, baseurl: string, url: string, subdir: string, error: string|false}
	 */
	public $original_upload_dir;

	/**
	 * @var ?string
	 */
	private $region = null;

	/**
	 * @var ?Aws\S3\S3Client
	 */
	private $s3 = null;

	/**
	 * @var ?static
	 */
	private static $instance = null;

	/**
	 *
	 * @return static
	 */
	public static function get_instance() {

		if ( ! self::$instance ) {
			self::$instance = new static(
				self::get_configured_bucket(),
				defined( 'R2_UPLOADS_KEY' ) ? R2_UPLOADS_KEY : null,
				defined( 'R2_UPLOADS_SECRET' ) ? R2_UPLOADS_SECRET : null,
				defined( 'R2_UPLOADS_PUBLIC_URL' ) ? R2_UPLOADS_PUBLIC_URL : null,
				defined( 'R2_UPLOADS_REGION' ) ? R2_UPLOADS_REGION : 'auto',
				defined( 'R2_UPLOADS_ACCOUNT_ID' ) ? R2_UPLOADS_ACCOUNT_ID : null
			);
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @param string $bucket
	 * @param ?string $key
	 * @param ?string $secret
	 * @param ?string $bucket_url
	 * @param ?string $region
	 * @param ?string $account_id
	 */
	public function __construct( $bucket, $key, $secret, $bucket_url = null, $region = null, $account_id = null ) {
		$this->bucket     = $bucket;
		$this->key        = $key;
		$this->secret     = $secret;
		$this->bucket_url = $bucket_url !== null ? untrailingslashit( $bucket_url ) : null;
		$this->region     = $region;
		$this->account_id = $account_id;
	}

	private static function get_configured_bucket() : string {
		$bucket = R2_UPLOADS_BUCKET;
		$prefix = defined( 'R2_UPLOADS_BUCKET_PATH_PREFIX' ) ? trim( R2_UPLOADS_BUCKET_PATH_PREFIX, '/' ) : '';

		return $prefix !== '' ? $bucket . '/' . $prefix : $bucket;
	}

	/**
	 * Setup the hooks, urls filtering etc for S3 Uploads
	 */
	public function setup() : void {
		$this->register_stream_wrapper();

		add_filter( 'upload_dir', [ $this, 'filter_upload_dir' ] );
		add_filter( 'wp_image_editors', [ $this, 'filter_editors' ], 9 );
		add_action( 'delete_attachment', [ $this, 'delete_attachment_files' ] );
		add_filter( 'wp_read_image_metadata', [ $this, 'wp_filter_read_image_metadata' ], 10, 2 );
		add_filter( 'wp_resource_hints', [ $this, 'wp_filter_resource_hints' ], 10, 2 );

		add_filter( 'wp_handle_sideload_prefilter', [ $this, 'filter_sideload_move_temp_file_to_s3' ] );
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'set_filesize_in_attachment_meta' ], 10, 2 );

		add_filter( 'wp_get_attachment_url', [ $this, 'add_s3_signed_params_to_attachment_url' ], 10, 2 );
		add_filter( 'wp_get_attachment_image_src', [ $this, 'add_s3_signed_params_to_attachment_image_src' ], 10, 2 );
		add_filter( 'wp_calculate_image_srcset', [ $this, 'add_s3_signed_params_to_attachment_image_srcset' ], 10, 5 );

		add_filter( 'pre_wp_unique_filename_file_list', [ $this, 'get_files_for_unique_filename_file_list' ], 10, 3 );
	}

	/**
	 * Tear down the hooks, url filtering etc for S3 Uploads
	 */
	public function tear_down() : void {

		stream_wrapper_unregister( 's3' );
		remove_filter( 'upload_dir', [ $this, 'filter_upload_dir' ] );
		remove_filter( 'wp_image_editors', [ $this, 'filter_editors' ], 9 );
		remove_filter( 'wp_handle_sideload_prefilter', [ $this, 'filter_sideload_move_temp_file_to_s3' ] );
		remove_filter( 'wp_generate_attachment_metadata', [ $this, 'set_filesize_in_attachment_meta' ] );

		remove_filter( 'wp_get_attachment_url', [ $this, 'add_s3_signed_params_to_attachment_url' ] );
		remove_filter( 'wp_get_attachment_image_src', [ $this, 'add_s3_signed_params_to_attachment_image_src' ] );
		remove_filter( 'wp_calculate_image_srcset', [ $this, 'add_s3_signed_params_to_attachment_image_srcset' ] );

	}

	/**
	 * Register the stream wrapper for R2's S3-compatible API.
	 */
	public function register_stream_wrapper() : void {
		Stream_Wrapper::register( $this );
		stream_context_set_option( stream_context_get_default(), 's3', 'seekable', true );
	}

	/**
	 * Get the s3:// path for the bucket.
	 */
	public function get_s3_path() : string {
		return 's3://' . $this->bucket;
	}

	/**
	 * Overwrite the default wp_upload_dir.
	 *
	 * @param array{path: string, basedir: string, baseurl: string, url: string, subdir: string, error: string|false} $dirs
	 * @return array{path: string, basedir: string, baseurl: string, url: string, subdir: string, error: string|false}
	 */
	public function filter_upload_dir( array $dirs ) : array {

		$this->original_upload_dir = $dirs;
		$dirs['subdir'] = $this->get_upload_subdir( $dirs['subdir'] );
		$s3_path = $this->get_s3_path();

		$dirs['basedir'] = $s3_path;
		$dirs['path']    = $s3_path . $dirs['subdir'];

		if ( ! defined( 'R2_UPLOADS_DISABLE_REPLACE_UPLOAD_URL' ) || ! R2_UPLOADS_DISABLE_REPLACE_UPLOAD_URL ) {
			$dirs['baseurl'] = $this->get_s3_url();
			$dirs['url']     = $this->get_s3_url() . $dirs['subdir'];
		}

		return $dirs;
	}

	private function get_upload_subdir( string $subdir ) : string {
		$parts = [];

		if ( defined( 'R2_UPLOADS_ADD_YEAR_MONTH_TO_BUCKET_PATH' ) ) {
			if ( R2_UPLOADS_ADD_YEAR_MONTH_TO_BUCKET_PATH ) {
				$parts[] = current_time( 'Y' );
				$parts[] = current_time( 'm' );
			}
		} elseif ( $subdir !== '' ) {
			$parts[] = trim( $subdir, '/' );
		}

		if ( defined( 'R2_UPLOADS_ADD_OBJECT_VERSION_TO_BUCKET_PATH' ) && R2_UPLOADS_ADD_OBJECT_VERSION_TO_BUCKET_PATH ) {
			$parts[] = (string) $this->get_object_version();
		}

		$parts = array_filter( array_map( 'trim', $parts ), 'strlen' );
		return empty( $parts ) ? '' : '/' . implode( '/', $parts );
	}

	private function get_object_version() : int {
		static $version = null;

		if ( $version === null ) {
			$version = time();
		}

		return $version;
	}

	/**
	 * Delete all attachment files from S3 when an attachment is deleted.
	 *
	 * WordPress Core's handling of deleting files for attachments via
	 * wp_delete_attachment_files is not compatible with remote streams, as
	 * it makes many assumptions about local file paths. The hooks also do
	 * not exist to be able to modify their behavior. As such, we just clean
	 * up the s3 files when an attachment is removed, and leave WordPress to try
	 * a failed attempt at mangling the s3:// urls.
	 *
	 * @param int $post_id
	 */
	public function delete_attachment_files( int $post_id ) : void {
		$meta = wp_get_attachment_metadata( $post_id );
		$file = get_attached_file( $post_id );
		if ( $file === false ) {
			return;
		}

		if ( isset( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $sizeinfo ) {
				$intermediate_file = str_replace( basename( $file ), $sizeinfo['file'], $file );
				wp_delete_file( $intermediate_file );
			}
		}

		wp_delete_file( $file );
	}

	/**
	 * Get the S3 URL base for uploads.
	 *
	 * @return string
	 */
	public function get_s3_url() : string {
		$bucket = strtok( $this->bucket, '/' );
		$path   = substr( $this->bucket, strlen( $bucket ) );

		if ( $this->bucket_url !== null ) {
			return $this->bucket_url . $path;
		}

		if ( $this->account_id !== null ) {
			$jurisdiction = defined( 'R2_UPLOADS_JURISDICTION' ) && R2_UPLOADS_JURISDICTION !== '' ? '.' . R2_UPLOADS_JURISDICTION : '';
			return 'https://' . $this->get_s3_bucket() . '.' . $this->account_id . $jurisdiction . '.r2.cloudflarestorage.com' . $path;
		}

		$url = apply_filters( 'r2_uploads_bucket_url', '' );
		return $url;
	}

	/**
	 * Get the S3 bucket name
	 *
	 * @return string
	 */
	public function get_s3_bucket() : string {
		return strtok( $this->bucket, '/' );
	}

	/**
	 * Get the region of the S3 bucket.
	 *
	 * @return string
	 */
	public function get_s3_bucket_region() : ?string {
		return $this->region;
	}

	/**
	 * Get the original upload directory before it was replaced by S3 uploads.
	 *
	 * @return array{path: string, basedir: string, baseurl: string, url: string, subdir: string, error: string|false}
	 */
	public function get_original_upload_dir() : array {

		if ( empty( $this->original_upload_dir ) ) {
			wp_upload_dir();
		}

		/**
		 * @var array{path: string, basedir: string, baseurl: string, url: string, subdir: string, error: string|false}
		 */
		$upload_dir = $this->original_upload_dir;
		return $upload_dir;
	}

	/**
	 * Reverse a file url in the uploads directory to the params needed for S3.
	 *
	 * @param string $url
	 * @return array{bucket: string, key: string, query: string|null}|null
	 */
	public function get_s3_location_for_url( string $url ) : ?array {
		$upload_dir = wp_upload_dir();

		if ( strpos( $url, $upload_dir['baseurl'] ) === false ) {
			return null;
		}

		$path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $url );
		$parsed = wp_parse_url( $path );
		if ( ! isset( $parsed['host'] ) || ! isset( $parsed['path'] ) ) {
			return null;
		}
		return [
			'bucket' => $parsed['host'],
			'key'    => ltrim( $parsed['path'], '/' ),
			'query'  => $parsed['query'] ?? null,
		];
	}

	/**
	 * Reverse a file path in the uploads directory to the params needed for S3.
	 *
	 * @param string $url
	 * @return array{key: string, bucket: string}
	 */
	public function get_s3_location_for_path( string $path ) : ?array {
		$parsed = wp_parse_url( $path );
		if ( ! isset( $parsed['path'] ) || ! isset( $parsed['host'] ) || ! isset( $parsed['scheme'] ) || $parsed['scheme'] !== 's3' ) {
			return null;
		}
		return [
			'bucket' => $parsed['host'],
			'key'    => ltrim( $parsed['path'], '/' ),
		];
	}

	/**
	 * @return Aws\S3\S3Client
	 */
	public function s3() : Aws\S3\S3Client {

		if ( ! empty( $this->s3 ) ) {
			return $this->s3;
		}

		$this->s3 = $this->get_aws_sdk()->createS3();
		return $this->s3;
	}

	/**
	 * Get the AWS Sdk.
	 *
	 * @return Aws\Sdk
	 */
	public function get_aws_sdk() : Aws\Sdk {
		/** @var null|Aws\Sdk */
		$sdk = apply_filters( 'r2_uploads_aws_sdk', null, $this );
		if ( $sdk ) {
			return $sdk;
		}

		$params = [
			'version' => 'latest',
			'region' => $this->region ?: 'auto',
			'signature' => 'v4',
			'request_checksum_calculation' => 'when_required',
			'response_checksum_validation' => 'when_required',
		];

		if ( $this->account_id !== null ) {
			$jurisdiction = defined( 'R2_UPLOADS_JURISDICTION' ) && R2_UPLOADS_JURISDICTION !== '' ? '.' . R2_UPLOADS_JURISDICTION : '';
			$params['endpoint'] = 'https://' . $this->account_id . $jurisdiction . '.r2.cloudflarestorage.com';
		}

		if ( $this->key !== null && $this->secret !== null ) {
			$params['credentials']['key'] = $this->key;
			$params['credentials']['secret'] = $this->secret;
		}

		if ( defined( 'WP_PROXY_HOST' ) && defined( 'WP_PROXY_PORT' ) ) {
			$proxy_auth    = '';
			$proxy_address = WP_PROXY_HOST . ':' . WP_PROXY_PORT;

			if ( defined( 'WP_PROXY_USERNAME' ) && defined( 'WP_PROXY_PASSWORD' ) ) {
				$proxy_auth = WP_PROXY_USERNAME . ':' . WP_PROXY_PASSWORD . '@';
			}

			$params['request.options']['proxy'] = $proxy_auth . $proxy_address;
		}

		$params = apply_filters( 'r2_uploads_s3_client_params', $params );

		$sdk = new Aws\Sdk( $params );
		return $sdk;
	}

	public function filter_editors( array $editors ) : array {
		$position = array_search( 'WP_Image_Editor_Imagick', $editors );
		if ( $position !== false ) {
			unset( $editors[ $position ] );
		}

		array_unshift( $editors, __NAMESPACE__ . '\\Image_Editor_Imagick' );

		return $editors;
	}
	/**
	 * Copy the file from /tmp to an s3 dir so handle_sideload doesn't fail due to
	 * trying to do a rename() on the file cross streams. This is somewhat of a hack
	 * to work around the core issue https://core.trac.wordpress.org/ticket/29257
	 *
	 * @param array{tmp_name: string, name: string, type: string, size: int, error: int} $file File array
	 * @return array{tmp_name: string, name: string, type: string, size: int, error: int}
	 */
	public function filter_sideload_move_temp_file_to_s3( array $file ) {
		$upload_dir = wp_upload_dir();
		$new_path = $upload_dir['basedir'] . '/tmp/' . basename( $file['tmp_name'] );

		copy( $file['tmp_name'], $new_path );
		unlink( $file['tmp_name'] );
		$file['tmp_name'] = $new_path;

		return $file;
	}

	/**
	 * Store the attachment filesize in the attachment meta array.
	 *
	 * Getting the filesize of an image in S3 involves a remote HEAD request,
	 * which is a bit slower than a local filesystem operation would be. As a
	 * result, operations like `wp_prepare_attachments_for_js' take substantially
	 * longer to complete against s3 uploads than if they were performed with a
	 * local filesystem.i
	 *
	 * Saving the filesize in the attachment metadata when the image is
	 * uploaded allows core to skip this stat when retrieving and formatting it.
	 *
	 * @param array<string, mixed> $metadata      Attachment metadata.
	 * @param int                  $attachment_id Attachment ID.
	 * @return array<string, mixed> Attachment metadata array, with "filesize" value added.
	 */
	function set_filesize_in_attachment_meta( array $metadata, int $attachment_id ) : array {
		$file = get_attached_file( $attachment_id );
		if ( $file === false ) {
			return $metadata;
		}
		if ( ! isset( $metadata['filesize'] ) && file_exists( $file ) ) {
			$metadata['filesize'] = filesize( $file );
		}

		return $metadata;
	}

	/**
	 * Filters wp_read_image_metadata. exif_read_data() doesn't work on
	 * file streams so we need to make a temporary local copy to extract
	 * exif data from.
	 *
	 * @param array<string, mixed> $meta
	 * @param string $file
	 * @return array<string, mixed>|false
	 */
	public function wp_filter_read_image_metadata( array $meta, string $file ) {
		remove_filter( 'wp_read_image_metadata', [ $this, 'wp_filter_read_image_metadata' ], 10 );
		$temp_file = $this->copy_image_from_s3( $file );
		$meta = wp_read_image_metadata( $temp_file );
		add_filter( 'wp_read_image_metadata', [ $this, 'wp_filter_read_image_metadata' ], 10, 2 );
		unlink( $temp_file );
		return $meta;
	}

	/**
	 * Add the DNS address for the S3 Bucket to list for DNS prefetch.
	 *
	 * @param array $hints
	 * @param string $relation_type
	 * @return array
	 */
	function wp_filter_resource_hints( array $hints, string $relation_type ) : array {
		if ( defined( 'R2_UPLOADS_DISABLE_REPLACE_UPLOAD_URL' ) && R2_UPLOADS_DISABLE_REPLACE_UPLOAD_URL ) {
			return $hints;
		}

		if ( 'dns-prefetch' === $relation_type ) {
			$hints[] = $this->get_s3_url();
		}

		return $hints;
	}

	/**
	 * Get a local copy of the file.
	 *
	 * @param string $file
	 * @return string
	 */
	public function copy_image_from_s3( string $file ) : string {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/file.php' );
		}
		$temp_filename = wp_tempnam( $file );
		copy( $file, $temp_filename );
		return $temp_filename;
	}

	/**
	 * Check if the attachment is private.
	 *
	 * @param integer $attachment_id
	 * @return boolean
	 */
	public function is_private_attachment( int $attachment_id ) : bool {
		/**
		 * Filters whether an attachment should be private.
		 *
		 * @param bool Whether the attachment is private.
		 * @param int  The attachment ID.
		 */
		$private = apply_filters( 'r2_uploads_is_attachment_private', false, $attachment_id );
		return $private;
	}

	/**
	 * Get all the files stored for a given attachment.
	 *
	 * @param integer $attachment_id
	 * @return list<string> Array of all full paths to the attachment's files.
	 */
	public static function get_attachment_files( int $attachment_id ) : array {
		/** @var string */
		$main_file = get_attached_file( $attachment_id );
		$main_file_directory = dirname( $main_file );
		$files = [ $main_file ];

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( isset( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size => $sizeinfo ) {
				$files[] = $main_file_directory . '/' . $sizeinfo['file'];
			}
		}

		/** @var string|false */
		$original_image = get_post_meta( $attachment_id, 'original_image', true );
		if ( $original_image !== false && $original_image !== '' ) {
			$files[] = $main_file_directory . '/' . $original_image;
		}

		/** @var array<string,array{file: string}> */
		$backup_sizes = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
		if ( $backup_sizes ) {
			foreach ( $backup_sizes as $size => $sizeinfo ) {
				// Backup sizes only store the backup filename, which is relative to the
				// main attached file, unlike the metadata sizes array.
				$files[] = $main_file_directory . '/' . $sizeinfo['file'];
			}
		}

		$files = apply_filters( 'r2_uploads_get_attachment_files', $files, $attachment_id );

		return $files;
	}

	/**
	 * Add the S3 signed params onto an image for for a given attachment.
	 *
	 * This function determines whether the attachment needs a signed URL, so is safe to
	 * pass any URL.
	 *
	 * @param string $url
	 * @param integer $post_id
	 * @return string
	 */
	public function add_s3_signed_params_to_attachment_url( string $url, int $post_id ) : string {
		if ( ! $this->is_private_attachment( $post_id ) ) {
			return $url;
		}
		$path = $this->get_s3_location_for_url( $url );
		if ( ! $path ) {
			return $url;
		}
		$cmd = $this->s3()->getCommand(
			'GetObject',
			[
				'Bucket' => $path['bucket'],
				'Key' => $path['key'],
			]
		);

		$presigned_url_expires = apply_filters( 'r2_uploads_private_attachment_url_expiry', '+6 hours', $post_id );
		$url = (string) $this->s3()->createPresignedRequest( $cmd, $presigned_url_expires )->getUri();
		$url = apply_filters( 'r2_uploads_presigned_url', $url, $post_id );

		return $url;
	}

	/**
	 * Add the S3 signed params to an image src array.
	 *
	 * @param array{0: string, 1: int, 2: int}|false $image
	 * @param integer|"" $post_id The post id, due to WordPress hook, this can be "", so can't just hint as int.
	 * @return array{0: string, 1: int, 2: int}|false
	 */
	public function add_s3_signed_params_to_attachment_image_src( $image, $post_id ) {
		if ( $image === false || $post_id === '' || $post_id === 0 ) {
			return $image;
		}

		$image[0] = $this->add_s3_signed_params_to_attachment_url( $image[0], $post_id );
		return $image;
	}

	/**
	 * Add the S3 signed params to the image srcset (response image) sizes.
	 *
	 * @param array{url: string, descriptor: string, value: int}[] $sources
	 * @param array $sizes
	 * @param string $src
	 * @param array $meta
	 * @param integer $post_id
	 * @return array{url: string, descriptor: string, value: int}[]
	 */
	public function add_s3_signed_params_to_attachment_image_srcset( array $sources, array $sizes, string $src, array $meta, int $post_id ) : array {
		foreach ( $sources as &$source ) {
			$source['url'] = $this->add_s3_signed_params_to_attachment_url( $source['url'], $post_id );
		}
		return $sources;
	}

	/**
	 * Override the files used for wp_unique_filename() comparisons
	 *
	 * @param array|null $files
	 * @param string $dir
	 * @return array
	 */
	public function get_files_for_unique_filename_file_list( ?array $files, string $dir, string $filename ) : array {
		$name = pathinfo( $filename, PATHINFO_FILENAME );
		// The s3:// streamwrapper support listing by partial prefixes with wildcards.
		// For example, scandir( s3://bucket/2019/06/my-image* )
		$scandir = scandir( trailingslashit( $dir ) . $name . '*' );
		if ( $scandir === false ) {
			$scandir = []; // Set as empty array for return
		}
		return $scandir;
	}
}
