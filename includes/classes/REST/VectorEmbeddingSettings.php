<?php
/**
 * Vector Embedding Configuration REST API Controller.
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\REST;

use ElasticPress\Utils;
use ElasticPressLabs\Feature\VectorEmbeddings\Settings;

/**
 * Vector Embedding Configuration API controller class.
 */
class VectorEmbeddingSettings {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'elasticpress-labs/v1',
			'vector-embeddings',
			[
				'callback'            => [ $this, 'update_settings' ],
				'methods'             => 'POST',
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $this->get_endpoint_args_for_item_schema( true ),
			]
		);
	}

	/**
	 * Check that the request has permission to save the vector embeddings settings.
	 *
	 * @since 2.5.2
	 * @return boolean
	 */
	public function check_permission() {
		$capability = Utils\get_capability();

		return current_user_can( $capability );
	}

	/**
	 * Get the endpoint arguments for the item schema.
	 *
	 * @return array
	 */
	protected function get_endpoint_args_for_item_schema() {
		$schema = $this->get_item_schema();

		$args = [];

		foreach ( $schema['properties'] as $property_id => $property_schema ) {
			$args[ $property_id ] = [
				'required'          => ! empty( $property_schema['required'] ),
				'type'              => $property_schema['type'],
				'description'       => $property_schema['description'],
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'rest_sanitize_request_arg',
			];
		}

		return $args;
	}

	/**
	 * Get the item schema.
	 *
	 * Objects only accept the properties listed here. Unknown properties are removed
	 * before validation (see `remove_unknown_properties()`), so settings saved by older
	 * versions can still be saved again.
	 *
	 * @since 2.4.0, 2.5.2 describes the settings the settings screen saves.
	 * @return array
	 */
	public function get_item_schema() {
		$taxonomy_schema = [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'name'         => [
					'description' => __( 'Taxonomy name.', 'elasticpress-labs' ),
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 32,
					'required'    => true,
				],
				'label'        => [
					'description' => __( 'Taxonomy label.', 'elasticpress-labs' ),
					'type'        => 'string',
				],
				'termsInclude' => [
					'description' => __( 'IDs of the terms whose posts are included.', 'elasticpress-labs' ),
					'type'        => 'array',
					'items'       => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'required'    => true,
				],
				'termsExclude' => [
					'description' => __( 'IDs of the terms whose posts are excluded.', 'elasticpress-labs' ),
					'type'        => 'array',
					'items'       => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'required'    => true,
				],
				'enabled'      => [
					'description' => __( 'Whether the taxonomy rules are enabled.', 'elasticpress-labs' ),
					'type'        => 'boolean',
				],
			],
		];

		$string_list_schema = [
			'type'  => 'array',
			'items' => [
				'type' => 'string',
			],
		];

		return [
			'$schema'              => 'http://json-schema.org/draft-04/schema#',
			'title'                => 'vector-embeddings',
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'chunkSize'      => [
					'description' => __( 'Chunk size, in words.', 'elasticpress-labs' ),
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 300,
					'required'    => true,
				],
				'chunkOverlap'   => [
					'description' => __( 'Chunk overlap, in words.', 'elasticpress-labs' ),
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => 100,
					'required'    => true,
				],
				'postTypeConfig' => [
					'description' => __( 'Post type configurations.', 'elasticpress-labs' ),
					'type'        => 'array',
					'required'    => true,
					'items'       => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'key'                   => [
								'description' => __( 'Post type key.', 'elasticpress-labs' ),
								'type'        => 'string',
								'pattern'     => '^[a-z0-9_-]{1,20}$',
								'required'    => true,
							],
							'label'                 => [
								'description' => __( 'Post type label.', 'elasticpress-labs' ),
								'type'        => 'string',
							],
							'embeddable'            => [
								'description' => __( 'Whether posts of this type can be embedded.', 'elasticpress-labs' ),
								'type'        => 'boolean',
								'required'    => true,
							],
							'embeddingMode'         => [
								'description' => __( 'Whether posts are embedded automatically or only when flagged.', 'elasticpress-labs' ),
								'type'        => 'string',
								'enum'        => [ 'automatic', 'manual' ],
								'required'    => true,
							],
							'enableFieldsIndexing'  => [
								'description' => __( 'Whether post meta flags posts for inclusion or exclusion.', 'elasticpress-labs' ),
								'type'        => 'boolean',
							],
							'fieldsIndexingInclude' => array_merge(
								$string_list_schema,
								[ 'description' => __( 'Meta keys that flag posts for inclusion.', 'elasticpress-labs' ) ]
							),
							'fieldsIndexingExclude' => array_merge(
								$string_list_schema,
								[ 'description' => __( 'Meta keys that flag posts for exclusion.', 'elasticpress-labs' ) ]
							),
							'fieldsEmbedding'       => array_merge(
								$string_list_schema,
								[ 'description' => __( 'Fields used to generate the embeddings.', 'elasticpress-labs' ) ]
							),
							'taxonomies'            => [
								'description'          => __( 'Taxonomies settings.', 'elasticpress-labs' ),
								'type'                 => 'object',
								'additionalProperties' => $taxonomy_schema,
							],
						],
					],
				],
			],
		];
	}

	/**
	 * Remove the properties the schema does not list from objects, recursively.
	 *
	 * The settings screen sends values it only displays (`indexablePostTypes`, `embeddingsFiltered`),
	 * and settings saved by older versions can carry keys that were renamed since.
	 *
	 * @since 2.5.2
	 * @param mixed $value  The value.
	 * @param array $schema The schema of the value.
	 * @return mixed
	 */
	protected function remove_unknown_properties( $value, array $schema ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$type = $schema['type'] ?? '';

		if ( 'array' === $type && isset( $schema['items'] ) ) {
			foreach ( $value as $index => $item ) {
				$value[ $index ] = $this->remove_unknown_properties( $item, $schema['items'] );
			}

			return $value;
		}

		if ( 'object' !== $type ) {
			return $value;
		}

		if ( isset( $schema['properties'] ) ) {
			$value = array_intersect_key( $value, $schema['properties'] );

			foreach ( $value as $property => $property_value ) {
				$value[ $property ] = $this->remove_unknown_properties( $property_value, $schema['properties'][ $property ] );
			}

			return $value;
		}

		if ( isset( $schema['additionalProperties'] ) && is_array( $schema['additionalProperties'] ) ) {
			foreach ( $value as $property => $property_value ) {
				$value[ $property ] = $this->remove_unknown_properties( $property_value, $schema['additionalProperties'] );
			}
		}

		return $value;
	}

	/**
	 * Update vector embeddings.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function update_settings( \WP_REST_Request $request ) {
		$settings = $this->remove_unknown_properties( $request->get_params(), $this->get_item_schema() );

		// Validate settings
		$valid = rest_validate_value_from_schema( $settings, $this->get_item_schema(), 'vector-embeddings' );
		if ( is_wp_error( $valid ) ) {
			$valid->add_data( [ 'status' => 400 ] );
			return $valid;
		}

		$sanitized = rest_sanitize_value_from_schema( $settings, $this->get_item_schema(), 'vector-embeddings' );
		if ( is_wp_error( $sanitized ) ) {
			$sanitized->add_data( [ 'status' => 400 ] );
			return $sanitized;
		}

		update_option( SETTINGS::SETTINGS_KEY, $sanitized );

		return rest_ensure_response( [ 'success' => true ] );
	}
}
