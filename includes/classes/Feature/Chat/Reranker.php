<?php
/**
 * Chat re-rank stage.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

use ElasticPress\Features;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Re-orders retrieved passages using the configured provider.
 */
class Reranker {
	/**
	 * Apply re-ranking when the selected bot has it on.
	 *
	 * @param array  $passages Retrieved passages.
	 * @param string $question User question.
	 * @param array  $bot      Selected chat bot.
	 * @return array
	 */
	public function apply( array $passages, string $question, array $bot ): array {
		if ( empty( $bot['rerank_enabled'] ) || [] === $passages ) {
			return $passages;
		}

		$documents = $this->to_documents( $passages );

		if ( [] === $documents ) {
			return $passages;
		}

		$provider = $this->get_provider( $question, $bot );

		if ( ! $provider instanceof Rerank_Provider ) {
			return $passages;
		}

		$ranked = $provider->rerank( $question, $documents );

		if ( is_wp_error( $ranked ) || ! is_array( $ranked ) ) {
			return $passages;
		}

		return $this->order_passages( $passages, $ranked, $bot );
	}

	/**
	 * Resolve a provider, or null when the connection is not configured.
	 *
	 * @param string $question User question.
	 * @param array  $bot      Selected chat bot.
	 * @return Rerank_Provider|null
	 */
	protected function get_provider( string $question, array $bot ) {
		/**
		 * Filters the Chat re-ranker provider instance.
		 *
		 * Return a Rerank_Provider to replace the HTTP client. Return null
		 * to use the configured Cohere-compatible endpoint.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_rerank_provider
		 * @param {Rerank_Provider|null} $provider Provider, or null for default.
		 * @param {string}               $question User question.
		 * @param {array}                $bot      Selected chat bot.
		 * @return {Rerank_Provider|null} Provider.
		 */
		$provider = apply_filters( 'ep_chat_rerank_provider', null, $question, $bot );

		if ( $provider instanceof Rerank_Provider ) {
			return $provider;
		}

		$chat = Features::factory()->get_registered_feature( 'chat' );

		if ( ! $chat instanceof Chat || ! $chat->is_rerank_set_up() ) {
			return null;
		}

		$url = trim( (string) $chat->get_setting( 'rerank_api_url' ) );

		if ( ! $this->is_usable_url( $url ) ) {
			return null;
		}

		// The key is optional: a re-ranker on the local network may need none.
		return new Cohere_Compatible_Reranker(
			$url,
			trim( (string) $chat->get_setting( 'rerank_api_key' ) ),
			trim( (string) $chat->get_setting( 'rerank_model' ) )
		);
	}

	/**
	 * Accept any http(s) URL with a host, including localhost and .test.
	 *
	 * @param string $url Candidate endpoint.
	 * @return bool
	 */
	protected function is_usable_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) ) {
			return false;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = (string) ( $parts['host'] ?? '' );

		return in_array( $scheme, [ 'http', 'https' ], true ) && '' !== $host;
	}

	/**
	 * Shape passages for the provider. IDs stay on the document.
	 *
	 * @param array $passages Retrieved passages.
	 * @return array
	 */
	protected function to_documents( array $passages ): array {
		$documents = [];

		foreach ( $passages as $passage ) {
			if ( ! is_array( $passage ) || ! array_key_exists( 'id', $passage ) ) {
				continue;
			}

			$text = trim(
				implode(
					' ',
					array_filter(
						[
							(string) ( $passage['title'] ?? '' ),
							(string) ( $passage['excerpt'] ?? '' ),
						]
					)
				)
			);

			if ( '' === $text ) {
				continue;
			}

			$documents[] = [
				'id'   => $passage['id'],
				'text' => $text,
			];
		}

		return $documents;
	}

	/**
	 * Re-order by provider scores bound to passage ID. Apply the optional floor.
	 *
	 * @param array $passages Retrieved passages.
	 * @param array $ranked   Provider rows [ id, score ].
	 * @param array $bot      Selected chat bot.
	 * @return array
	 */
	protected function order_passages( array $passages, array $ranked, array $bot ): array {
		$by_id = [];

		foreach ( $passages as $passage ) {
			if ( ! is_array( $passage ) || ! array_key_exists( 'id', $passage ) ) {
				continue;
			}

			$by_id[ (string) $passage['id'] ] = $passage;
		}

		$floor   = $this->get_floor( $bot );
		$seen    = [];
		$ordered = [];

		foreach ( $ranked as $row ) {
			if ( ! is_array( $row ) || ! array_key_exists( 'id', $row ) ) {
				continue;
			}

			$key = (string) $row['id'];

			if ( ! isset( $by_id[ $key ] ) || isset( $seen[ $key ] ) ) {
				continue;
			}

			$score = isset( $row['score'] ) && is_numeric( $row['score'] )
				? (float) $row['score']
				: null;

			if ( null !== $floor && ( null === $score || $score < $floor ) ) {
				continue;
			}

			$passage                 = $by_id[ $key ];
			$passage['rerank_score'] = $score;
			$ordered[]               = $passage;
			$seen[ $key ]            = true;
		}

		return $ordered;
	}

	/**
	 * Optional score floor: the bot's, else the Chat feature's.
	 *
	 * @param array $bot Selected chat bot.
	 * @return float|null
	 */
	protected function get_floor( array $bot ) {
		return Settings::get( 'rerank_floor', $bot );
	}
}
