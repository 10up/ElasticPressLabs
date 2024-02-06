<?php
/**
 * External Content integration Feature
 *
 * With this feature, if a meta key contains a path or a URL, it is possible to
 * index the content of that path or URL. If the meta key is `meta_key` and its
 * value is `https://wordpress.org/news/wp-json/wp/v2/posts/16837` the JSON returned
 * by that REST API endpoint will be indexed in a meta key called `ep_external_content_meta_key`.
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
		add_filter( 'ep_external_content_file_content', [ $this, 'maybe_parse_js' ], 10, 2 );
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

		/**
		 * Filter the list meta keys that contain paths or URLs to external content
		 *
		 * @since 2.3.0
		 * @hook ep_external_content_meta_keys
		 * @param {array} $meta_keys List of meta keys
		 * @return {array} New list of meta keys
		 */
		return apply_filters( 'ep_external_content_meta_keys', $meta_keys );
	}

	/**
	 * Given a meta key, return the meta key that will store the external content
	 *
	 * @param string $meta_key The meta key
	 * @return string
	 */
	public function get_stored_meta_key( $meta_key ) {
		/**
		 * Filter the meta key that will contain the external content.
		 *
		 * If a meta key `meta_key_1` has `https://wordpress.org/news/wp-json/wp/v2/posts/16837`
		 * as its value, the `ep_external_content_meta_key_1` field would have that post JSON as its value.
		 * With this filter it is possible to change that `ep_external_content_meta_key_1` meta key.
		 *
		 * @since 2.3.0
		 * @hook ep_external_content_stored_meta_key
		 * @param {string} $stored_meta_key Meta key that holds the actual external content
		 * @param {string} $meta_key Meta key that contains the external content path or URL
		 * @return {string} New $stored_meta_key
		 */
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
	public function maybe_parse_js( $content, $path_or_url ) {
		if ( stripos( $path_or_url, '.js' ) !== false ) {
			/**
			 * Filter the method of parsing JavaScript files.
			 *
			 * If the external content path or URL is a JS file, it is possible to parse its content.
			 * Passing `only_strings` (default) only strings will be stored. Passing `remove_js_reserved_words`
			 * all JS reserved words will be removed, leaving strings and function names, for example.
			 * If anything else is sent, the content is not changed.
			 *
			 * @since 2.3.0
			 * @hook ep_external_content_parse_js_method
			 * @param {string} $method Method to parse the JS file. Could be `only_strings` or `remove_js_reserved_words`.
			 * @return {string} New $method
			 */
			$method = apply_filters( 'ep_external_content_parse_js_method', 'only_strings' );

			if ( 'only_strings' === $method && preg_match_all( '/([\'"])(?:\\\1|(?!\1).)*?\1/', $content, $matches ) ) {
				$content = implode( ' ', $matches[0] );
			}

			if ( 'remove_js_reserved_words' === $method ) {
				$content = str_replace( get_js_reserved_words(), '', $content );
			}
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
		/**
		 * Filter the content.
		 *
		 * @since 2.3.0
		 * @hook ep_external_content_file_content
		 * @param {string} $content     Content being processed
		 * @param {string} $path_or_url Path or URL
		 * @param {string} $meta_key    The meta key that contains the path or URL
		 * @param {array}  $post_meta   Post meta
		 * @return {string} New $content
		 */
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
