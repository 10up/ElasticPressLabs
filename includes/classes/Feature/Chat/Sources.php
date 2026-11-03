<?php
/**
 * What the Sources rail shows about each source besides the answer's quote.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Details added to an answer's sources when it is shown or exported, not when it is written, so
 * they follow the site's content as it is now (only for answers the Sources rail shows):
 *
 * - `notes`: what readers should know about a source, supplied by the site through
 *   `ep_chat_source_notes` (Labs adds none). Each is a chip on the source card, with an
 *   optional longer text the chip opens.
 * - `citation`: the reference line "Copy citations" copies, neutral by default and filterable
 *   through `ep_chat_source_citation`.
 */
class Sources {
	/**
	 * Note tones, and so chip colors.
	 *
	 * @var string[]
	 */
	public const TONES = [ 'info', 'warning' ];

	/**
	 * Notes by post ID, for this request.
	 *
	 * @var array<int, array>
	 */
	protected static $notes = [];

	/**
	 * A source's notes.
	 *
	 * @param int $post_id Source post ID.
	 * @return array<int, array{label: string, description: string, text: string, tone: string}>
	 */
	public static function get_notes( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return [];
		}

		if ( isset( self::$notes[ $post_id ] ) ) {
			return self::$notes[ $post_id ];
		}

		/**
		 * Filters what readers should know about a source. Each note is a chip on the source's card
		 * in the Sources rail, and is exported with the answer's citations.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_source_notes
		 * @param {array} $notes   Notes. Each: `label` (the chip text, required), `description`
		 *                         (shown on hover and read by screen readers), `text` (a longer
		 *                         note the chip opens), and `tone` (`info` or `warning`). Default none.
		 * @param {int}   $post_id Source post ID.
		 * @return {array} Filtered notes.
		 */
		$filtered = apply_filters( 'ep_chat_source_notes', [], $post_id );
		$notes    = [];

		foreach ( is_array( $filtered ) ? $filtered : [] as $note ) {
			if ( ! is_array( $note ) ) {
				continue;
			}

			$label = self::clean( $note['label'] ?? '' );

			if ( '' === $label ) {
				continue;
			}

			$tone    = (string) ( $note['tone'] ?? '' );
			$notes[] = [
				'label'       => $label,
				'description' => self::clean( $note['description'] ?? '' ),
				'text'        => self::clean( $note['text'] ?? '' ),
				'tone'        => in_array( $tone, self::TONES, true ) ? $tone : 'info',
			];
		}

		self::$notes[ $post_id ] = $notes;

		return $notes;
	}

	/**
	 * The reference line for a source: `<Site name>. (<Year>, <Month> <Day>). <Title>. <URL>`,
	 * filterable so a site can use its own style.
	 *
	 * @param array $hit Answer hit: `id`, `title`, `url`.
	 * @return string
	 */
	public static function get_citation( array $hit ): string {
		$post_id = (int) ( $hit['id'] ?? 0 );
		$title   = trim( wp_specialchars_decode( wp_strip_all_tags( (string) ( $hit['title'] ?? '' ) ), ENT_QUOTES ) );
		$url     = (string) ( $hit['url'] ?? '' );
		$date    = $post_id ? get_the_date( 'Y, F j', $post_id ) : '';
		$parts   = [
			trim( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			'(' . ( $date ? $date : __( 'n.d.', 'elasticpress-labs' ) ) . ')',
			rtrim( $title, '.' ),
		];

		$citation = implode( '. ', array_filter( $parts, 'strlen' ) ) . '.' . ( '' !== $url ? ' ' . $url : '' );

		/**
		 * Filters the reference line "Copy citations" copies for a source.
		 *
		 * @since 2.6.0
		 * @hook ep_chat_source_citation
		 * @param {string} $citation Plain text. Default: `<Site name>. (<Year>, <Month> <Day>). <Title>. <URL>`.
		 * @param {int}    $post_id  Source post ID.
		 * @param {array}  $hit      Answer hit: `id`, `number`, `title`, `url`, `date`.
		 * @return {string} Filtered line.
		 */
		return self::clean( apply_filters( 'ep_chat_source_citation', $citation, $post_id, $hit ) );
	}

	/**
	 * An answer's hits with each source's `notes`, and a `citation` for the sources the answer
	 * cites (the only ones "Copy citations" copies).
	 *
	 * @param array $hits       Answer hits.
	 * @param array $provenance Answer provenance (`n` is the cited hit's number).
	 * @return array
	 */
	public static function attach( array $hits, array $provenance = [] ): array {
		$cited = [];

		foreach ( $provenance as $entry ) {
			if ( is_array( $entry ) && isset( $entry['n'] ) ) {
				$cited[ (int) $entry['n'] ] = true;
			}
		}

		foreach ( $hits as $index => $hit ) {
			if ( ! is_array( $hit ) || ! isset( $hit['id'] ) ) {
				continue;
			}

			$hits[ $index ]['notes'] = self::get_notes( (int) $hit['id'] );

			if ( isset( $cited[ (int) ( $hit['number'] ?? 0 ) ] ) ) {
				$hits[ $index ]['citation'] = self::get_citation( $hit );
			}
		}

		return $hits;
	}

	/**
	 * An answer payload (stored, or a sync reply) with its sources' details, for an answer the
	 * Sources rail shows. Declined and failed answers show none, so they get none.
	 *
	 * @param array  $payload Payload: `hits`, `provenance`.
	 * @param string $status  Question status.
	 * @return array
	 */
	public static function attach_to_payload( array $payload, string $status ): array {
		if ( in_array( $status, [ Storage::STATUS_REFUSED, Storage::STATUS_CLARIFY, Storage::STATUS_ERROR ], true ) || ! isset( $payload['hits'] ) || ! is_array( $payload['hits'] ) ) {
			return $payload;
		}

		$payload['hits'] = self::attach( $payload['hits'], is_array( $payload['provenance'] ?? null ) ? $payload['provenance'] : [] );

		return $payload;
	}

	/**
	 * Forget the notes read in this request.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$notes = [];
	}

	/**
	 * Plain text on one line.
	 *
	 * @param mixed $text Text.
	 * @return string
	 */
	protected static function clean( $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
	}
}
