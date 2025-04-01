<?php
/**
 * AI Search Summary REST API Controller.
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\REST;

use ElasticPressLabs\Feature\AISearchSummary as AISearchSummaryFeature;

/**
 * AI Search Summary REST API controller class.
 */
class AISearchSummary {
	/**
	 * The AISearchSummaryFeature instance.
	 *
	 * @var AISearchSummaryFeature
	 */
	protected $feature;

	/**
	 * Class constructor
	 *
	 * @param AISearchSummaryFeature $feature The feature instance.
	 */
	public function __construct( AISearchSummaryFeature $feature ) {
		$this->feature = $feature;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$routes = [
			'client-side' => [
				'callback'            => [ $this, 'get_ai_search_summary_response' ],
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'args'                => [
					'search_query'   => [
						'description'       => __( 'The search query.', 'elasticpress-labs' ),
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'search_vectors' => [
						'description'       => __( 'The search vectors.', 'elasticpress-labs' ),
						'type'              => 'array',
						'sanitize_callback' => [ $this, 'sanitize_vectors_array' ],
					],
				],
			],
			'server-side' => [
				'callback'            => [ $this, 'get_ai_search_summary_response' ],
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => [
					'search_query' => [
						'description'       => __( 'The search query.', 'elasticpress-labs' ),
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			],
		];

		register_rest_route(
			'elasticpress-labs/v1',
			'ai-search-summary',
			$routes[ $this->get_embed_method() ],
		);
	}

	/**
	 * Get the AI response for a given query.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return object|\WP_Error
	 */
	public function get_ai_search_summary_response( \WP_REST_Request $request ) {
		$ai_response = $this->feature->get_ai_response( $request['search_query'], $request['search_vectors'] ?? null );

		if ( ! is_wp_error( $ai_response ) ) {
			$class = 'ep-ai-search-summary-success';
			$html  = $this->format_response( $ai_response );
		} else {
			$class = str_replace( '_', '-', $ai_response->get_error_code() );
			$html  = $ai_response->get_error_message();
		}

		return [
			'class' => $class,
			'html'  => $html,
		];
	}

	/**
	 * Sanitize vectors array.
	 *
	 * @param array $array Array to be sanitized
	 * @return array
	 */
	public function sanitize_vectors_array( array $array ): array {
		return array_map( 'floatval', $array );
	}

	/**
	 * Get the embedding method of the search term.
	 *
	 * @return string
	 */
	protected function get_embed_method(): string {
		return (string) $this->feature->get_setting( 'search_term_embed_method' );
	}

	/**
	 * Formats the AI response into a string.
	 *
	 * @param mixed $ai_response The AI response data to be formatted.
	 * @return string The formatted response as a string.
	 */
	protected function format_response( $ai_response ): string {
		$ai_response       = trim( $ai_response, "'" );
		$ai_response_array = json_decode( $ai_response, true );

		$html = '';
		if ( json_last_error() === JSON_ERROR_NONE && isset( $ai_response_array['response'] ) ) {
			$html = wp_kses_post( str_replace( '\\\\', '\\', $ai_response_array['response'] ) );
			if ( ! empty( $ai_response_array['references'] ) ) {
				$html .= '<div class="ep-ai-search-summary--references">';
				$html .= '<p>' . esc_html__( 'Sources:', 'elasticpress-labs' ) . '</p>';
				$html .= '<ul>';
				foreach ( $ai_response_array['references'] as $reference ) {
					$html .= '<li>';
					$html .= '<a href="' . esc_url( $reference['url'] ) . '" target="_blank" rel="noopener noreferrer">';
					$html .= esc_html( $reference['title'] );
					$html .= '</a>';
					$html .= '</li>';
				}
				$html .= '</ul>';
				$html .= '</div>';
			}
		}

		/**
		 * Filters the formatted HTML response generated from the AI response.
		 *
		 * @since 2.5.0
		 * @hook ep_ai_search_summary_formatted_response
		 * @param {string} $html        The formatted HTML response.
		 * @param {mixed}  $ai_response The raw AI response data.
		 * @return {string} Filtered HTML response.
		 */
		return apply_filters( 'ep_ai_search_summary_formatted_response', $html, $ai_response );
	}
}
