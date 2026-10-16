<?php
/**
 * Checks on an Elasticsearch query a model wrote.
 *
 * @since 2.6.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * The security boundary of the AI-generated query: the question is part of the prompt, so the
 * model's reply is untrusted input. Only `query`, `knn`, and `sort` are kept, built from a short
 * list of clause types over the fields of Index_Schema. Code adds everything else (size, source,
 * the post filter, the query vector, inner hits).
 *
 * - A clause type outside the list rejects the query (it could run a script or read any field).
 * - A clause on a field outside the list is dropped: the search gets wider, and the field is never
 *   read. A `multi_match` without fields gets the allowed text fields.
 * - A taxonomy slug is kept only when the taxonomy's terms are listed and the slug is one of them;
 *   any other slug is matched by name, as the model may guess slugs that do not exist.
 * - Depth, number of clauses, string length, and values per `terms` are capped.
 *
 * A query with nothing left to search is rejected. A rejected query is a WP_Error.
 */
class Query_Validator {
	/**
	 * Deepest clause, the top clause being 0.
	 *
	 * @var int
	 */
	public const MAX_DEPTH = 6;

	/**
	 * Most clauses in one query.
	 *
	 * @var int
	 */
	public const MAX_CLAUSES = 40;

	/**
	 * Longest string value, in characters.
	 *
	 * @var int
	 */
	public const MAX_STRING = 500;

	/**
	 * Most values in one `terms` clause.
	 *
	 * @var int
	 */
	public const MAX_TERMS = 50;

	/**
	 * Largest kNN `k`.
	 *
	 * @var int
	 */
	public const MAX_K = 100;

	/**
	 * Largest kNN `num_candidates`.
	 *
	 * @var int
	 */
	public const MAX_CANDIDATES = 1000;

	/**
	 * Date formats a range on a date field reads, when the model gives none.
	 *
	 * @var string
	 */
	public const DATE_FORMATS = 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||yyyy-MM||yyyy';

	/**
	 * Field types a range or a sort can use, besides `date`.
	 *
	 * @var string[]
	 */
	protected const NUMBER_TYPES = [ 'long', 'integer', 'short', 'byte', 'double', 'float', 'half_float', 'scaled_float', 'unsigned_long' ];

	/**
	 * Allowed fields, as Index_Schema::get_fields() returns them.
	 *
	 * @var array<string, array{type: string, note: string}>
	 */
	protected $fields;

	/**
	 * Listed taxonomy terms, as Index_Schema::get_terms() returns them.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected $terms;

	/**
	 * Clauses counted so far in the current query.
	 *
	 * @var int
	 */
	protected $clauses = 0;

	/**
	 * Constructor.
	 *
	 * @param array $fields Allowed fields: path => [ 'type' => string, … ].
	 * @param array $terms  Listed taxonomies: taxonomy => [ slug => name ].
	 */
	public function __construct( array $fields, array $terms = [] ) {
		$this->fields = $fields;
		$this->terms  = $terms;
	}

	/**
	 * Check a query and keep its safe parts.
	 *
	 * @param mixed $query Decoded model reply.
	 * @return array|\WP_Error `query`, `knn`, and `sort`, as present; or why it was rejected.
	 */
	public function validate( $query ) {
		$this->clauses = 0;

		if ( ! is_array( $query ) ) {
			return $this->error( 'not_object', __( 'The query is not a JSON object.', 'elasticpress-labs' ) );
		}

		$valid = [];

		if ( isset( $query['query'] ) ) {
			$clause = $this->clause( $query['query'], 0 );

			if ( is_wp_error( $clause ) ) {
				return $clause;
			}

			if ( null !== $clause ) {
				$valid['query'] = $clause;
			}
		}

		if ( isset( $query['knn'] ) ) {
			$knn = $this->knn( $query['knn'] );

			if ( is_wp_error( $knn ) ) {
				return $knn;
			}

			if ( null !== $knn ) {
				$valid['knn'] = $knn;
			}
		}

		if ( ! isset( $valid['query'] ) && ! isset( $valid['knn'] ) ) {
			return $this->error( 'empty', __( 'The query has nothing left to search.', 'elasticpress-labs' ) );
		}

		if ( isset( $query['sort'] ) ) {
			$sort = $this->sort( $query['sort'] );

			if ( $sort ) {
				$valid['sort'] = $sort;
			}
		}

		return $valid;
	}

	/**
	 * Check one query clause.
	 *
	 * @param mixed $clause Clause: one key, its type.
	 * @param int   $depth  Depth, the top clause being 0.
	 * @return array|null|\WP_Error The clause, null to drop it, or an error to reject the query.
	 */
	protected function clause( $clause, int $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			return $this->error( 'too_deep', __( 'The query is nested too deep.', 'elasticpress-labs' ) );
		}

		if ( ++$this->clauses > self::MAX_CLAUSES ) {
			return $this->error( 'too_many_clauses', __( 'The query has too many clauses.', 'elasticpress-labs' ) );
		}

		$clause = is_object( $clause ) ? (array) $clause : $clause;

		if ( ! is_array( $clause ) || 1 !== count( $clause ) || ! is_string( key( $clause ) ) ) {
			return $this->malformed();
		}

		$type = (string) key( $clause );
		$body = is_object( reset( $clause ) ) ? (array) reset( $clause ) : reset( $clause );

		switch ( $type ) {
			case 'bool':
				return $this->bool( $body, $depth );
			case 'match':
			case 'match_phrase':
				return $this->match( $type, $body );
			case 'multi_match':
				return $this->multi_match( $body );
			case 'term':
				return $this->term( $body );
			case 'terms':
				return $this->terms( $body );
			case 'range':
				return $this->range( $body );
			case 'exists':
				return $this->exists( $body );
			case 'nested':
				return $this->nested( $body, $depth );
			case 'match_all':
				return [ 'match_all' => new \stdClass() ];
		}

		return $this->error(
			'clause_not_allowed',
			/* translators: %s: Elasticsearch query clause type */
			sprintf( __( 'The query uses a clause that is not allowed: %s.', 'elasticpress-labs' ), $type )
		);
	}

	/**
	 * Check a `bool` clause. Each occurrence is a list of checked clauses; an empty bool is
	 * dropped.
	 *
	 * @param mixed $body  Clause body.
	 * @param int   $depth Depth of the bool clause.
	 * @return array|null|\WP_Error
	 */
	protected function bool( $body, int $depth ) {
		if ( ! is_array( $body ) ) {
			return $this->malformed();
		}

		$bool = [];
		$kept = 0;

		foreach ( $body as $key => $value ) {
			if ( in_array( $key, [ 'must', 'filter', 'should', 'must_not' ], true ) ) {
				$list    = is_array( $value ) && $this->is_list( $value ) ? $value : [ $value ];
				$checked = [];

				foreach ( $list as $item ) {
					$item = $this->clause( $item, $depth + 1 );

					if ( is_wp_error( $item ) ) {
						return $item;
					}

					if ( null !== $item ) {
						$checked[] = $item;
					}
				}

				if ( $checked ) {
					$bool[ $key ] = $checked;
					$kept        += count( $checked );
				}
			} elseif ( 'minimum_should_match' === $key && ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^-?\d+%?$/', $value ) ) ) ) {
				$bool[ $key ] = $value;
			} elseif ( 'boost' === $key && is_numeric( $value ) ) {
				$bool[ $key ] = $this->boost( $value );
			}
		}

		return $kept ? [ 'bool' => $bool ] : null;
	}

	/**
	 * Check a `match` or `match_phrase` clause on a text or keyword field.
	 *
	 * @param string $type `match` or `match_phrase`.
	 * @param mixed  $body Clause body: one field.
	 * @return array|null|\WP_Error
	 */
	protected function match( string $type, $body ) {
		$field = $this->single_field( $body );

		if ( is_wp_error( $field ) ) {
			return $field;
		}

		$value = $body[ $field ];

		if ( is_array( $value ) ) {
			$options = 'match' === $type ? [ 'operator', 'minimum_should_match', 'boost' ] : [ 'slop', 'boost' ];
			$checked = [];

			if ( ! isset( $value['query'] ) || ! is_scalar( $value['query'] ) ) {
				return $this->malformed();
			}

			foreach ( $value as $key => $option ) {
				if ( 'query' === $key ) {
					$checked['query'] = $this->scalar( $option );
				} elseif ( in_array( $key, $options, true ) && null !== $this->option( $key, $option ) ) {
					$checked[ $key ] = $this->option( $key, $option );
				}
			}

			$value = $checked;
		} elseif ( is_scalar( $value ) ) {
			$value = $this->scalar( $value );
		} else {
			return $this->malformed();
		}

		if ( ! $this->is_field( $field, [ 'text', 'keyword' ] ) ) {
			return null;
		}

		return [ $type => [ $field => $value ] ];
	}

	/**
	 * Check a `multi_match` clause: its fields are cut to the allowed text and keyword fields
	 * outside nested paths, or, when it has none, set to the allowed text fields.
	 *
	 * @param mixed $body Clause body.
	 * @return array|null|\WP_Error
	 */
	protected function multi_match( $body ) {
		if ( ! is_array( $body ) || ! isset( $body['query'] ) || ! is_scalar( $body['query'] ) ) {
			return $this->malformed();
		}

		$checked = [];

		foreach ( $body as $key => $value ) {
			if ( 'query' === $key ) {
				$checked['query'] = $this->scalar( $value );
			} elseif ( 'fields' === $key ) {
				$fields = [];

				foreach ( (array) $value as $field ) {
					if ( is_string( $field ) && preg_match( '/^([^\^]+)(\^\d+(?:\.\d+)?)?$/', $field, $parts ) && $this->is_field( $parts[1], [ 'text', 'keyword' ] ) && ! $this->in_nested( $parts[1] ) ) {
						$fields[] = $field;
					}
				}

				if ( ! $fields ) {
					return null;
				}

				$checked['fields'] = $fields;
			} elseif ( 'type' === $key && in_array( $value, [ 'best_fields', 'most_fields', 'cross_fields', 'phrase', 'phrase_prefix' ], true ) ) {
				$checked['type'] = $value;
			} elseif ( in_array( $key, [ 'operator', 'minimum_should_match', 'boost' ], true ) && null !== $this->option( $key, $value ) ) {
				$checked[ $key ] = $this->option( $key, $value );
			}
		}

		if ( ! isset( $checked['fields'] ) ) {
			$checked['fields'] = $this->get_text_fields();

			if ( ! $checked['fields'] ) {
				return null;
			}
		}

		return [ 'multi_match' => $checked ];
	}

	/**
	 * Check a `term` clause. A taxonomy slug is checked (see slug_clause()).
	 *
	 * @param mixed $body Clause body: one field.
	 * @return array|null|\WP_Error
	 */
	protected function term( $body ) {
		$field = $this->single_field( $body );

		if ( is_wp_error( $field ) ) {
			return $field;
		}

		$value = $body[ $field ];
		$boost = null;

		if ( is_array( $value ) ) {
			if ( ! isset( $value['value'] ) || ! is_scalar( $value['value'] ) ) {
				return $this->malformed();
			}

			$boost = isset( $value['boost'] ) && is_numeric( $value['boost'] ) ? $this->boost( $value['boost'] ) : null;
			$value = $value['value'];
		} elseif ( ! is_scalar( $value ) ) {
			return $this->malformed();
		}

		$value = $this->scalar( $value );

		if ( ! $this->is_field( $field, null ) ) {
			return null;
		}

		$taxonomy = $this->get_slug_taxonomy( $field );

		if ( null !== $taxonomy && ! $this->is_listed_slug( $taxonomy, (string) $value ) ) {
			return $this->match_name( $taxonomy, (string) $value );
		}

		return [
			'term' => [
				$field => null === $boost ? $value : [
					'value' => $value,
					'boost' => $boost,
				],
			],
		];
	}

	/**
	 * Check a `terms` clause. Taxonomy slugs that are not listed are matched by name: the clause
	 * becomes a bool `should` of the known slugs and one name match per other slug.
	 *
	 * @param mixed $body Clause body: one field, and an optional boost.
	 * @return array|null|\WP_Error
	 */
	protected function terms( $body ) {
		if ( ! is_array( $body ) ) {
			return $this->malformed();
		}

		$boost = isset( $body['boost'] ) && is_numeric( $body['boost'] ) ? $this->boost( $body['boost'] ) : null;

		unset( $body['boost'] );

		$field = $this->single_field( $body );

		if ( is_wp_error( $field ) ) {
			return $field;
		}

		if ( ! is_array( $body[ $field ] ) || ! $this->is_list( $body[ $field ] ) ) {
			return $this->malformed();
		}

		$values = [];

		foreach ( array_slice( $body[ $field ], 0, self::MAX_TERMS ) as $value ) {
			if ( ! is_scalar( $value ) ) {
				return $this->malformed();
			}

			$values[] = $this->scalar( $value );
		}

		if ( ! $values || ! $this->is_field( $field, null ) ) {
			return null;
		}

		$taxonomy = $this->get_slug_taxonomy( $field );
		$known    = $values;
		$names    = [];

		if ( null !== $taxonomy ) {
			$known = [];

			foreach ( $values as $value ) {
				if ( $this->is_listed_slug( $taxonomy, (string) $value ) ) {
					$known[] = $value;
				} else {
					$match = $this->match_name( $taxonomy, (string) $value );

					if ( null !== $match ) {
						$names[] = $match;
					}
				}
			}
		}

		$parts = $known ? [ [ 'terms' => array_merge( [ $field => $known ], null === $boost ? [] : [ 'boost' => $boost ] ) ] ] : [];
		$parts = array_merge( $parts, $names );

		if ( ! $parts ) {
			return null;
		}

		if ( 1 === count( $parts ) ) {
			return $parts[0];
		}

		return [
			'bool' => [
				'should'               => $parts,
				'minimum_should_match' => 1,
			],
		];
	}

	/**
	 * Check a `range` clause on a date or number field.
	 *
	 * @param mixed $body Clause body: one field.
	 * @return array|null|\WP_Error
	 */
	protected function range( $body ) {
		$field = $this->single_field( $body );

		if ( is_wp_error( $field ) ) {
			return $field;
		}

		if ( ! is_array( $body[ $field ] ) ) {
			return $this->malformed();
		}

		$range = [];

		foreach ( $body[ $field ] as $key => $value ) {
			if ( in_array( $key, [ 'gte', 'gt', 'lte', 'lt', 'format' ], true ) ) {
				if ( ! is_scalar( $value ) ) {
					return $this->malformed();
				}

				$range[ $key ] = $this->scalar( $value );
			} elseif ( 'boost' === $key && is_numeric( $value ) ) {
				$range[ $key ] = $this->boost( $value );
			}
		}

		if ( ! array_intersect_key( $range, array_flip( [ 'gte', 'gt', 'lte', 'lt' ] ) ) || ! $this->is_field( $field, array_merge( [ 'date' ], self::NUMBER_TYPES ) ) ) {
			return null;
		}

		// ElasticPress maps dates as `yyyy-MM-dd HH:mm:ss` only; a model writes `2020-01-01`.
		if ( ! isset( $range['format'] ) && $this->is_field( $field, [ 'date' ] ) ) {
			$range['format'] = self::DATE_FORMATS;
		}

		return [ 'range' => [ $field => $range ] ];
	}

	/**
	 * Check an `exists` clause.
	 *
	 * @param mixed $body Clause body.
	 * @return array|null|\WP_Error
	 */
	protected function exists( $body ) {
		if ( ! is_array( $body ) || ! isset( $body['field'] ) || ! is_string( $body['field'] ) ) {
			return $this->malformed();
		}

		return $this->is_field( $body['field'], null ) ? [ 'exists' => [ 'field' => $body['field'] ] ] : null;
	}

	/**
	 * Check a `nested` clause: an allowed nested path and a checked query. Inner hits are code's
	 * to set, so they are dropped.
	 *
	 * @param mixed $body  Clause body.
	 * @param int   $depth Depth of the nested clause.
	 * @return array|null|\WP_Error
	 */
	protected function nested( $body, int $depth ) {
		if ( ! is_array( $body ) || ! isset( $body['path'], $body['query'] ) || ! is_string( $body['path'] ) ) {
			return $this->malformed();
		}

		$query = $this->clause( $body['query'], $depth + 1 );

		if ( is_wp_error( $query ) || null === $query ) {
			return $query;
		}

		if ( ! $this->is_field( $body['path'], [ 'nested' ] ) ) {
			return null;
		}

		$nested = [
			'path'  => $body['path'],
			'query' => $query,
		];

		if ( isset( $body['score_mode'] ) && in_array( $body['score_mode'], [ 'avg', 'max', 'min', 'none', 'sum' ], true ) ) {
			$nested['score_mode'] = $body['score_mode'];
		}

		return [ 'nested' => $nested ];
	}

	/**
	 * Check a top-level kNN search: an allowed vector field, capped `k` and `num_candidates`, and
	 * a checked filter. The vector and inner hits are removed: code sets them. A list keeps its
	 * first search.
	 *
	 * @param mixed $knn kNN search, or a list of them.
	 * @return array|null|\WP_Error
	 */
	protected function knn( $knn ) {
		$knn = is_object( $knn ) ? (array) $knn : $knn;

		if ( is_array( $knn ) && $this->is_list( $knn ) ) {
			$knn = $knn[0] ?? null;
			$knn = is_object( $knn ) ? (array) $knn : $knn;
		}

		if ( ! is_array( $knn ) || ! isset( $knn['field'] ) || ! is_string( $knn['field'] ) ) {
			return $this->malformed();
		}

		if ( ! $this->is_field( $knn['field'], [ 'dense_vector' ] ) ) {
			return null;
		}

		$k       = isset( $knn['k'] ) && is_numeric( $knn['k'] ) ? min( self::MAX_K, max( 1, (int) $knn['k'] ) ) : 10;
		$checked = [
			'field'          => $knn['field'],
			'k'              => $k,
			'num_candidates' => isset( $knn['num_candidates'] ) && is_numeric( $knn['num_candidates'] )
				? min( self::MAX_CANDIDATES, max( $k, (int) $knn['num_candidates'] ) )
				: min( self::MAX_CANDIDATES, $k * 10 ),
		];

		if ( isset( $knn['filter'] ) ) {
			$filters         = is_array( $knn['filter'] ) && $this->is_list( $knn['filter'] ) ? $knn['filter'] : [ $knn['filter'] ];
			$checked_filters = [];

			foreach ( $filters as $filter ) {
				$filter = $this->clause( $filter, 1 );

				if ( is_wp_error( $filter ) ) {
					return $filter;
				}

				if ( null !== $filter ) {
					$checked_filters[] = $filter;
				}
			}

			if ( $checked_filters ) {
				$checked['filter'] = $checked_filters;
			}
		}

		if ( isset( $knn['boost'] ) && is_numeric( $knn['boost'] ) ) {
			$checked['boost'] = $this->boost( $knn['boost'] );
		}

		return $checked;
	}

	/**
	 * Check a sort: `_score` and allowed sortable fields (keyword, date, number, boolean, not in a
	 * nested path), each as `[ field => [ 'order' => asc|desc ] ]`. Others are dropped.
	 *
	 * @param mixed $sort Sort: a field, a field => order map, or a list of them.
	 * @return array
	 */
	protected function sort( $sort ): array {
		$sort  = is_object( $sort ) ? (array) $sort : $sort;
		$items = [];

		if ( is_string( $sort ) ) {
			$items = [ [ $sort, 'desc' ] ];
		} elseif ( is_array( $sort ) && ! $this->is_list( $sort ) ) {
			foreach ( $sort as $field => $order ) {
				$items[] = [ $field, $order ];
			}
		} elseif ( is_array( $sort ) ) {
			foreach ( $sort as $item ) {
				$item = is_object( $item ) ? (array) $item : $item;

				if ( is_string( $item ) ) {
					$items[] = [ $item, 'desc' ];
				} elseif ( is_array( $item ) && 1 === count( $item ) ) {
					$items[] = [ key( $item ), reset( $item ) ];
				}
			}
		}

		$sortable = array_merge( [ 'keyword', 'date', 'boolean' ], self::NUMBER_TYPES );
		$checked  = [];

		foreach ( $items as list( $field, $order ) ) {
			$order = is_object( $order ) ? (array) $order : $order;
			$order = is_array( $order ) ? ( $order['order'] ?? '' ) : $order;
			$order = in_array( $order, [ 'asc', 'desc' ], true ) ? $order : 'desc';

			if ( '_score' === $field || ( is_string( $field ) && $this->is_field( $field, $sortable ) && ! $this->in_nested( $field ) ) ) {
				$checked[] = [ $field => [ 'order' => $order ] ];
			}
		}

		return $checked;
	}

	/**
	 * A name match for a taxonomy slug that is not listed: the slug's words, all required.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug     Slug the model wrote.
	 * @return array|null Null when the name field is not allowed.
	 */
	protected function match_name( string $taxonomy, string $slug ) {
		$field = 'terms.' . $taxonomy . '.name';
		$words = trim( preg_replace( '/[\s_-]+/', ' ', $slug ) );

		if ( '' === $words || ! $this->is_field( $field, [ 'text', 'keyword' ] ) ) {
			return null;
		}

		return [
			'match' => [
				$field => [
					'query'    => $words,
					'operator' => 'and',
				],
			],
		];
	}

	/**
	 * The taxonomy of a `terms.<taxonomy>.slug` field.
	 *
	 * @param string $field Field path.
	 * @return string|null
	 */
	protected function get_slug_taxonomy( string $field ) {
		return preg_match( '/^terms\.([^.]+)\.slug$/', $field, $matches ) ? $matches[1] : null;
	}

	/**
	 * Whether a slug is one of the listed terms of a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug     Slug.
	 * @return bool
	 */
	protected function is_listed_slug( string $taxonomy, string $slug ): bool {
		return isset( $this->terms[ $taxonomy ][ $slug ] );
	}

	/**
	 * Whether a field is allowed, and of one of the types.
	 *
	 * @param string        $field Field path.
	 * @param string[]|null $types Types, or null for any type that holds values.
	 * @return bool
	 */
	protected function is_field( string $field, $types ): bool {
		if ( ! isset( $this->fields[ $field ] ) ) {
			return false;
		}

		$type = (string) ( $this->fields[ $field ]['type'] ?? '' );

		if ( null === $types ) {
			return ! in_array( $type, [ 'object', 'nested', 'dense_vector' ], true );
		}

		return in_array( $type, $types, true );
	}

	/**
	 * Whether a field is inside a nested field.
	 *
	 * @param string $field Field path.
	 * @return bool
	 */
	protected function in_nested( string $field ): bool {
		$parts = explode( '.', $field );

		array_pop( $parts );

		for ( $count = count( $parts ); $count > 0; $count-- ) {
			if ( 'nested' === ( $this->fields[ implode( '.', array_slice( $parts, 0, $count ) ) ]['type'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The allowed text fields outside nested paths.
	 *
	 * @return string[]
	 */
	protected function get_text_fields(): array {
		$fields = [];

		foreach ( array_keys( $this->fields ) as $field ) {
			if ( $this->is_field( (string) $field, [ 'text' ] ) && ! $this->in_nested( (string) $field ) ) {
				$fields[] = (string) $field;
			}
		}

		return $fields;
	}

	/**
	 * Whether an array is a list (keys 0, 1, 2, …). `array_is_list()` needs PHP 8.1.
	 *
	 * @param array $value Array.
	 * @return bool
	 */
	protected function is_list( array $value ): bool {
		return array_values( $value ) === $value;
	}

	/**
	 * The field of a clause body with exactly one field.
	 *
	 * @param mixed $body Clause body.
	 * @return string|\WP_Error
	 */
	protected function single_field( $body ) {
		if ( ! is_array( $body ) || 1 !== count( $body ) || ! is_string( key( $body ) ) ) {
			return $this->malformed();
		}

		return (string) key( $body );
	}

	/**
	 * A checked option of a match clause.
	 *
	 * @param string $key   Option.
	 * @param mixed  $value Value.
	 * @return mixed|null Null when the value is not valid.
	 */
	protected function option( string $key, $value ) {
		switch ( $key ) {
			case 'operator':
				return in_array( $value, [ 'and', 'or', 'AND', 'OR' ], true ) ? strtolower( $value ) : null;
			case 'minimum_should_match':
				return is_int( $value ) || ( is_string( $value ) && preg_match( '/^-?\d+%?$/', $value ) ) ? $value : null;
			case 'slop':
				return is_numeric( $value ) ? min( 10, max( 0, (int) $value ) ) : null;
			case 'boost':
				return is_numeric( $value ) ? $this->boost( $value ) : null;
		}

		return null;
	}

	/**
	 * A boost between 0 and 100.
	 *
	 * @param mixed $value Numeric value.
	 * @return float
	 */
	protected function boost( $value ): float {
		return min( 100.0, max( 0.0, (float) $value ) );
	}

	/**
	 * A scalar value, strings cut to MAX_STRING characters.
	 *
	 * @param mixed $value Scalar.
	 * @return mixed
	 */
	protected function scalar( $value ) {
		return is_string( $value ) ? mb_substr( $value, 0, self::MAX_STRING ) : $value;
	}

	/**
	 * The error for a reply that is not a query.
	 *
	 * @return \WP_Error
	 */
	protected function malformed(): \WP_Error {
		return $this->error( 'malformed', __( 'The query is not valid Elasticsearch query JSON.', 'elasticpress-labs' ) );
	}

	/**
	 * A rejection.
	 *
	 * @param string $reason  Reason slug.
	 * @param string $message Message.
	 * @return \WP_Error
	 */
	protected function error( string $reason, string $message ): \WP_Error {
		return new \WP_Error( 'ep_chat_ai_query_' . $reason, $message );
	}
}
