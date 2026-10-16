<?php
/**
 * Cohere-compatible /rerank HTTP provider.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * POSTs query + documents and maps result indexes back to passage IDs.
 */
class Cohere_Compatible_Reranker implements Rerank_Provider {
	/**
	 * Endpoint URL.
	 *
	 * @var string
	 */
	protected $url;

	/**
	 * API key.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Model name.
	 *
	 * @var string
	 */
	protected $model;

	/**
	 * Constructor.
	 *
	 * @param string $url     Endpoint URL.
	 * @param string $api_key API key.
	 * @param string $model   Model name.
	 */
	public function __construct( string $url, string $api_key, string $model ) {
		$this->url     = $url;
		$this->api_key = $api_key;
		$this->model   = $model;
	}

	/**
	 * Score documents for a query.
	 *
	 * @param string $query     User question.
	 * @param array  $documents List of [ id => mixed, text => string ].
	 * @return array|\WP_Error
	 */
	public function rerank( string $query, array $documents ) {
		if ( [] === $documents ) {
			return [];
		}

		$response = wp_remote_post( $this->url, $this->get_request_options( $query, $documents ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return new \WP_Error(
				'ep_chat_rerank_http_' . $code,
				esc_html__( 'The re-ranker request failed.', 'elasticpress-labs' )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return new \WP_Error(
				'ep_chat_rerank_invalid_response',
				esc_html__( 'The re-ranker returned an invalid response.', 'elasticpress-labs' )
			);
		}

		return $this->normalize_results( $body, $documents );
	}

	/**
	 * The HTTP options of a re-rank request (the connection test sends the same).
	 *
	 * @since 2.6.0
	 * @param string $query     User question.
	 * @param array  $documents List of [ id => mixed, text => string ].
	 * @return array wp_remote_post options.
	 */
	public function get_request_options( string $query, array $documents ): array {
		$options = [
			'headers' => array_filter(
				[
					// No key, no Authorization header.
					'Authorization' => '' !== $this->api_key ? 'Bearer ' . $this->api_key : '',
					'Content-Type'  => 'application/json',
				]
			),
			'body'    => wp_json_encode(
				[
					'model'     => $this->model,
					'query'     => $query,
					'documents' => array_values(
						array_map(
							static function ( $document ) {
								return (string) ( $document['text'] ?? '' );
							},
							$documents
						)
					),
					'top_n'     => count( $documents ),
				]
			),
			'timeout' => 20,
		];

		/**
		 * Filters Chat re-ranker HTTP options.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_rerank_request_options
		 * @param {array}  $options   wp_remote_post options.
		 * @param {string} $url       Endpoint URL.
		 * @param {string} $query     User question.
		 * @return {array} Filtered options.
		 */
		return (array) apply_filters( 'ep_chat_rerank_request_options', $options, $this->url, $query );
	}

	/**
	 * Bind provider scores to passage IDs, never to list index.
	 *
	 * @param array $body      Decoded response.
	 * @param array $documents Documents in request order.
	 * @return array|\WP_Error
	 */
	public function normalize_results( array $body, array $documents ) {
		$results = $body['results'] ?? null;

		if ( ! is_array( $results ) ) {
			return new \WP_Error(
				'ep_chat_rerank_invalid_response',
				esc_html__( 'The re-ranker returned an invalid response.', 'elasticpress-labs' )
			);
		}

		$ranked = [];

		foreach ( $results as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$id = $this->passage_id_from_row( $row, $documents );

			if ( null === $id ) {
				continue;
			}

			$score = $row['relevance_score'] ?? $row['score'] ?? null;

			if ( ! is_numeric( $score ) ) {
				continue;
			}

			$ranked[] = [
				'id'    => $id,
				'score' => (float) $score,
			];
		}

		return $ranked;
	}

	/**
	 * Resolve a result row to the document ID that was sent.
	 *
	 * @param array $row       Provider result row.
	 * @param array $documents Documents in request order.
	 * @return mixed|null
	 */
	protected function passage_id_from_row( array $row, array $documents ) {
		if ( array_key_exists( 'index', $row ) && is_numeric( $row['index'] ) ) {
			$index = (int) $row['index'];

			if ( isset( $documents[ $index ]['id'] ) ) {
				return $documents[ $index ]['id'];
			}

			return null;
		}

		if ( array_key_exists( 'id', $row ) ) {
			foreach ( $documents as $document ) {
				if ( (string) ( $document['id'] ?? '' ) === (string) $row['id'] ) {
					return $document['id'];
				}
			}
		}

		return null;
	}
}
