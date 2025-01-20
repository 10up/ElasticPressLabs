<?php
/**
 * Vector Embeddings - Database storage
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 */

namespace ElasticPressLabs\Feature\VectorEmbeddings\Storage;

use ElasticPressLabs\Feature\VectorEmbeddings\VectorEmbeddings;

/**
 * Vector Embeddings - Database storage class
 */
class DbTable {
	/**
	 * VectorEmbeddings instance
	 *
	 * @var VectorEmbeddings
	 */
	protected $feature;

	/**
	 * Class constructor
	 *
	 * @param VectorEmbeddings $feature The VectorEmbeddings feature instance
	 */
	public function __construct( VectorEmbeddings $feature ) {
		$this->feature = $feature;
	}

	/**
	 * Setup hooks
	 */
	public function setup() {
		add_action( 'init', [ $this, 'create_table' ] );
	}

	/**
	 * Return the custom table name
	 *
	 * @return string
	 */
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'ep_embeddings_table';
	}

	/**
	 * Create the table
	 */
	public function create_table() {
		global $wpdb;

		if ( $this->table_exists() ) {
			return;
		}

		$table_name = $this->get_table_name();

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_id bigint(20) unsigned NOT NULL,
			object_type varchar(32) NOT NULL,
			hash varchar(32) NOT NULL,
			vectors longtext NOT NULL,
			PRIMARY KEY (id),
			INDEX object (object_id, object_type)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Whether or not the table exists
	 *
	 * @return boolean
	 */
	public function table_exists(): bool {
		global $wpdb;

		$table_name = $this->get_table_name();

		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
		);
		return ! \is_wp_error( $table_exists ) && ! \is_null( $table_exists );
	}

	/**
	 * Insert a new entry in the database
	 *
	 * @param integer $object_id   The object ID
	 * @param string  $object_type The object type
	 * @param string  $text        The text. It will be hashed and used as a key
	 * @param array   $vectors     Array of vectors
	 * @return void
	 */
	public function insert( int $object_id, string $object_type, string $text, array $vectors ) {
		global $wpdb;

		$table_name = $this->get_table_name();

		$wpdb->insert(
			$table_name,
			[
				'object_id'   => $object_id,
				'object_type' => $object_type,
				'hash'        => $this->hash_content( $text ),
				'vectors'     => wp_json_encode( $vectors ),
			]
		);
	}

	/**
	 * Given a text, return the vectors if they exist in the database
	 *
	 * @param string $text The text. It will be hashed and used as a key
	 * @return array|null
	 */
	public function get( string $text ) {
		global $wpdb;

		$table_name = $this->get_table_name();

		$vectors = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT vectors FROM %s WHERE hash = %s',
				$table_name,
				$this->hash_content( $text )
			)
		);

		return $vectors ? json_decode( $vectors ) : null;
	}

	/**
	 * Get a full list of hashes for a given object
	 *
	 * @param integer $object_id   The object ID
	 * @param string  $object_type The object type
	 * @return array
	 */
	public function get_all_object_hashes( int $object_id, string $object_type ): array {
		global $wpdb;

		$table_name = $this->get_table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT hash, vectors FROM %s WHERE object_id = %d AND object_type = %s',
				$table_name,
				$object_id,
				$object_type
			)
		);

		return array_reduce(
			$rows,
			function ( $carry, $row ) {
				$carry[ $row->hash ] = json_decode( $row->vectors );
				return $carry;
			},
			[]
		);
	}

	/**
	 * Delete a hash of a given object
	 *
	 * @param integer $object_id   The object ID
	 * @param string  $object_type The object type
	 * @param string  $hash        The hash
	 * @return void
	 */
	public function delete( int $object_id, string $object_type, string $hash ) {
		global $wpdb;

		$table_name = $this->get_table_name();

		$wpdb->delete(
			$table_name,
			[
				'object_id'   => $object_id,
				'object_type' => $object_type,
				'hash'        => $hash,
			]
		);
	}

	/**
	 * Hash the content. Uses md5 by default.
	 *
	 * @param string $content The content
	 * @return string
	 */
	public function hash_content( string $content ): string {
		return md5( $content );
	}
}
