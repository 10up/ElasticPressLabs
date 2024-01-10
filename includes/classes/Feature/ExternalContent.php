<?php
/**
 * External Content integration Feature
 *
 * @since 2.3.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature;

use ElasticPress\Feature;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * External Content feature
 *
 * @since 2.3.0
 */
class ExternalContent extends Feature {
	/**
	 * Initialize feature setting it's config
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		$this->slug = 'external_content';

		$this->title = esc_html__( 'External Content', 'elasticpress-labs' );

		parent::__construct();
	}

	/**
	 * Connects the Module with WordPress using Hooks and/or Filters.
	 *
	 * @return void
	 */
	public function setup() {
		add_filter( 'ep_prepare_meta_data', [ $this, 'append_external_content' ] );
		add_filter( 'ep_external_content_file_content', 'wp_strip_all_tags' );
		add_filter( 'ep_external_content_file_content', [ $this, 'maybe_remove_js_reserved_words' ], 10, 2 );
		add_filter( 'ep_prepare_meta_allowed_protected_keys', [ $this, 'allow_meta_keys' ], 10, 2 );
	}

	/**
	 * Tell user whether requirements for feature are met or not.
	 *
	 * @return FeatureRequirementsStatus Requirements object
	 */
	public function requirements_status() {
		return new \ElasticPress\FeatureRequirementsStatus( 1 );
	}

	/**
	 * Set the `settings_schema` attribute
	 */
	public function set_settings_schema() {
		$this->settings_schema = [
			[
				'default' => '',
				'help'    => '<p>' . __( 'Add one field per line', 'elasticpress-labs' ) . '</p>',
				'key'     => 'meta_fields',
				'label'   => __( 'Meta fields with external URLs', 'elasticpress-labs' ),
				'type'    => 'textarea',
			],
		];
	}

	/**
	 * Append external content to the document meta data
	 *
	 * @param array $post_meta Document's meta data
	 * @return array
	 */
	public function append_external_content( $post_meta ) {
		global $wp_filesystem;

		require_once ABSPATH . '/wp-admin/includes/file.php';
		WP_Filesystem();

		$meta_keys = $this->get_meta_keys();
		foreach ( $meta_keys as $meta_key ) {
			if ( ! isset( $post_meta[ $meta_key ] ) ) {
				continue;
			}

			$meta_value = (array) $post_meta[ $meta_key ];
			$meta_value = reset( $meta_value );

			/**
			 * The field value can either be a simple string or a JSON array with a list of URLs.
			 */
			$external_paths_and_urls = json_decode( $meta_value );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$external_paths_and_urls = (array) $meta_value;
			}

			if ( empty( $external_paths_and_urls ) ) {
				continue;
			}

			foreach ( $external_paths_and_urls as $external_path_and_url ) {
				if ( $wp_filesystem->exists( $external_path_and_url ) ) {
					$post_meta = $this->add_external_content_to_post_meta(
						$post_meta,
						$meta_key,
						$wp_filesystem->get_contents( $external_path_and_url ),
						$external_path_and_url
					);
				} else {
					$remote_get = wp_remote_get( $external_path_and_url );
					$post_meta  = $this->add_external_content_to_post_meta(
						$post_meta,
						$meta_key,
						wp_remote_retrieve_body( $remote_get ),
						$external_path_and_url
					);
				}
			}
		}

		return $post_meta;
	}

	/**
	 * Get the list of all meta keys that have external content to be indexed
	 *
	 * @return array
	 */
	public function get_meta_keys() {
		$meta_keys = preg_split( "/\r\n|\n|\r/", $this->get_setting( 'meta_fields' ) );

		return apply_filters( 'ep_external_content_meta_keys', $meta_keys );
	}

	/**
	 * Given a meta key, return the meta key that will store the external content
	 *
	 * @param string $meta_key The meta key
	 * @return string
	 */
	public function get_stored_meta_key( $meta_key ) {
		return apply_filters( 'ep_external_content_stored_meta_key', "ep_external_content_{$meta_key}", $meta_key );
	}

	/**
	 * Index stored meta keys
	 *
	 * @param array $meta_keys Existing post meta
	 * @return array
	 */
	public function allow_meta_keys( $meta_keys ) {
		$stored_meta_keys = array_reduce(
			$this->get_meta_keys(),
			function ( $acc, $meta_key ) {
				$acc[] = $this->get_stored_meta_key( $meta_key );
				return $acc;
			},
			[]
		);

		return array_unique(
			array_merge(
				$meta_keys,
				$stored_meta_keys
			)
		);
	}

	/**
	 * If the external content is a JavaScript file, remove all the reserved words
	 *
	 * @param string $content     File contents
	 * @param string $path_or_url File path or URL
	 * @return string
	 */
	public function maybe_remove_js_reserved_words( $content, $path_or_url ) {
		if ( str_ends_with( $path_or_url, '.js' ) ) {
			$content = str_replace( get_js_reserved_words(), '', $content );
		}
		return $content;
	}

	/**
	 * Add the content of external sources to the post meta array
	 *
	 * @param array  $post_meta   Array of all post meta
	 * @param string $meta_key    Meta key
	 * @param string $content     Contents of the external source
	 * @param string $path_or_url Path or URL of the external source
	 * @return array
	 */
	protected function add_external_content_to_post_meta( $post_meta, $meta_key, $content, $path_or_url ) {
		$content = apply_filters( 'ep_external_content_file_content', $content, $path_or_url, $meta_key, $post_meta );

		if ( empty( $content ) ) {
			return $post_meta;
		}

		$new_meta_key = $this->get_stored_meta_key( $meta_key );

		if ( ! isset( $post_meta[ $new_meta_key ] ) ) {
			$post_meta[ $new_meta_key ] = '';
		}

		$post_meta[ $new_meta_key ] .= ' ' . $content;

		return $post_meta;
	}
}
