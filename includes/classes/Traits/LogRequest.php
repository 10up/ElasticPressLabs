<?php
/**
 * Log Request trait
 *
 * This trait enables logging of requests.
 *
 * @since 2.5.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Traits;

trait LogRequest {

	/**
	 * Formatted request
	 *
	 * @var array $formatted_request.
	 */
	protected $formatted_request;

	/**
	 * Requests added to the ElasticPress query log, for every instance of the class.
	 *
	 * @since 2.6.0
	 * @var array
	 */
	protected static $query_log_requests = [];

	/**
	 * Send the request and log it.
	 *
	 * @param string $url                The URL to send the request to.
	 * @param array  $options            The options for the request.
	 * @param string $ep_query_type      The type of query.
	 * @param string $ep_query_type_slug The slug of the query type.
	 * @return array The response.
	 */
	public function send_request_and_log( $url, $options, $ep_query_type, $ep_query_type_slug ) {
		$formatted_request = [
			'time_start'   => microtime( true ),
			'time_finish'  => false,
			'args'         => $options,
			'blocking'     => true,
			'failed_hosts' => [],
			'request'      => false,
			'host'         => $url,
			'url'          => $url,
			'query_args'   => [],
		];

		// Make our API request.
		$response = wp_remote_post( $url, $options );

		$formatted_request['args']['method']        = 'POST';
		$formatted_request['args']['ep_query_type'] = $ep_query_type;
		$formatted_request['time_finish']           = microtime( true );
		$formatted_request['request']               = $response;
		$formatted_request['args']                  = $this->redact_secrets( $formatted_request['args'] );

		$this->formatted_request = $formatted_request;

		/*
		 * Like ElasticPress, keep requests for the query log only when debugging. One callback
		 * per class reads them all: a callback per request would keep every request object
		 * (and its response) alive for as long as the process runs. Only the latest requests are
		 * kept, and during a sync, the list is emptied whenever ElasticPress frees memory between
		 * batches.
		 */
		if ( $this->should_add_to_query_log() ) {
			self::$query_log_requests[] = $formatted_request;
			self::$query_log_requests   = array_slice( self::$query_log_requests, -$this->get_query_log_limit() );
			add_filter( 'ep_get_query_log', [ self::class, 'add_requests_to_query_log' ] );
			add_action( 'ep_stop_the_insanity', [ self::class, 'clear_query_log_requests' ] );
		}

		do_action( 'ep_remote_request', $formatted_request, $ep_query_type_slug );

		return $response;
	}

	/**
	 * Add the formatted request to the query log.
	 *
	 * @param array $query_log The query log.
	 * @return array The query log.
	 */
	public function add_vector_embeddings_to_query_log( $query_log ) {
		$query_log[] = $this->formatted_request;
		return $query_log;
	}

	/**
	 * Add the logged requests of this class to the query log.
	 *
	 * @since 2.6.0
	 * @param array $query_log The query log.
	 * @return array The query log.
	 */
	public static function add_requests_to_query_log( $query_log ) {
		return array_merge( (array) $query_log, self::$query_log_requests );
	}

	/**
	 * Forget the requests kept for the query log.
	 *
	 * @since 2.6.0
	 * @return void
	 */
	public static function clear_query_log_requests() {
		self::$query_log_requests = [];
	}

	/**
	 * Replace the `Authorization` header of request options, so the API key is not shown in the
	 * query log or stored with failed queries.
	 *
	 * @since 2.6.0
	 * @param array $options Request options.
	 * @return array
	 */
	protected function redact_secrets( array $options ): array {
		if ( empty( $options['headers'] ) || ! is_array( $options['headers'] ) ) {
			return $options;
		}

		foreach ( array_keys( $options['headers'] ) as $name ) {
			if ( 'authorization' === strtolower( (string) $name ) ) {
				$options['headers'][ $name ] = '[redacted]';
			}
		}

		return $options;
	}

	/**
	 * How many requests of a class are kept for the query log, the latest ones.
	 *
	 * @since 2.6.0
	 * @return int
	 */
	protected function get_query_log_limit(): int {
		/**
		 * Filters how many AI requests of one kind (Vector Embeddings, AI Search Summary) are kept
		 * for the query log when debugging is on. Older ones are dropped, so a long process does
		 * not keep every request and its response in memory.
		 *
		 * @since 2.6.0
		 * @hook ep_labs_query_log_requests_limit
		 * @param {int}    $limit      Requests kept. Default 100, at least 1.
		 * @param {string} $class_name Class that sent the requests.
		 * @return {int} Filtered limit.
		 */
		return max( 1, (int) apply_filters( 'ep_labs_query_log_requests_limit', 100, self::class ) );
	}

	/**
	 * Whether requests are kept for the query log: the same rule ElasticPress uses for its own
	 * queries (`WP_DEBUG` or `WP_EP_DEBUG`, unless `ep_disable_query_logging` is true).
	 *
	 * @since 2.6.0
	 * @return bool
	 */
	protected function should_add_to_query_log(): bool {
		$debug = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'WP_EP_DEBUG' ) && WP_EP_DEBUG );

		/** This filter is documented in elasticpress/includes/classes/Elasticsearch.php */
		return $debug && ! apply_filters( 'ep_disable_query_logging', false );
	}
}
