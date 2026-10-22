<?php
/**
 * Vector Embeddings - Post Indexable
 *
 * @since 2.4.0
 * @package ElasticPressLabs
 */

namespace ElasticPressLabs\Feature\VectorEmbeddings\Indexables\Post;

use ElasticPressLabs\Feature\VectorEmbeddings\Chunker;
use ElasticPressLabs\Feature\VectorEmbeddings\Indexable;
use ElasticPressLabs\Utils;
use ElasticPressLabs\Feature\VectorEmbeddings\Settings;

/**
 * Vector Embeddings - Post Indexable class
 */
class Post extends Indexable {
	/**
	 * Settings page instance.
	 *
	 * @var Settings
	 */
	public $settings_page;

	/**
	 * Setup hooks
	 */
	public function setup() {
		$this->settings_page = new Settings();
		$this->settings_page->setup();

		// Alter post and term mapping to store our vector embeddings
		add_filter( 'ep_post_mapping', [ $this, 'add_post_vector_field_mapping' ] );

		$generator = $this->feature->get_setting( 'ep_embeddings_generator' );
		if ( 'external' === $generator ) {
			return;
		}

		add_action( 'init', [ $this, 'register_meta' ], 20 );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_editor_assets' ] );

		add_action( 'post_submitbox_misc_actions', [ $this, 'output_embedding_exclude_setting' ] );
		add_action( 'attachment_submitbox_misc_actions', [ $this, 'output_embedding_exclude_setting' ], 15 );

		add_action( 'edit_post', [ $this, 'save_embedding_exclude_meta' ] );
		add_action( 'edit_attachment', [ $this, 'save_embedding_exclude_meta' ] );

		if ( 'epio' === $generator ) {
			add_filter( 'ep_bulk_index_action_args', [ $this, 'maybe_add_chunks_to_bulk_index_action_args' ], 10, 2 );
			add_filter( 'ep_post_sync_args_post_prepare_meta', [ $this, 'maybe_add_chunks_to_text_chunks_fields' ], 10, 2 );
			add_filter( 'ep_doc_status', [ $this, 'maybe_set_doc_status' ], 10, 3 );
			add_filter( 'ep_embeddings_dimensions', [ $this, 'set_epio_dimensions' ] );
		} else {
			add_filter( 'ep_post_sync_args_post_prepare_meta', [ $this, 'add_vector_field_to_post_sync' ], 10, 2 );
		}
	}

	/**
	 * Add our vector field mapping to the Elasticsearch post index.
	 *
	 * @param array $mapping Current mapping.
	 * @return array
	 */
	public function add_post_vector_field_mapping( array $mapping ): array {
		return $this->add_vector_mapping_field( $mapping );
	}

	/**
	 * Registers post meta for exclude from vector embeddings.
	 *
	 * @return void
	 */
	public function register_meta() {
		register_post_meta(
			'',
			'ep_embedding_exclude',
			[
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'boolean',
			]
		);

		register_post_meta(
			'',
			'ep_embedding_include',
			[
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'boolean',
			]
		);
	}

	/**
	 * Enqueue block editor assets.
	 */
	public function enqueue_block_editor_assets() {
		global $post;

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! $post->post_type || ! post_type_supports( $post->post_type, 'custom-fields' ) ) {
			return;
		}

		if ( ! $this->settings_page->is_post_type_embeddable( $post->post_type ) ) {
			return;
		}

		wp_enqueue_script(
			'ep-embeddings-editor',
			ELASTICPRESS_LABS_URL . 'dist/js/embeddings-editor-script.js',
			Utils\get_asset_info( 'embeddings-editor-script', 'dependencies' ),
			Utils\get_asset_info( 'embeddings-editor-script', 'version' ),
			true
		);

		wp_localize_script(
			'ep-embeddings-editor',
			'epEmbeddingsEditor',
			[
				'postTypeConfig' => $this->settings_page->get_post_type_config( $post->post_type ),
			]
		);

		wp_set_script_translations( 'ep-embeddings-editor', 'elasticpress-labs' );
	}

	/**
	 * Outputs the checkbox to exclude a post from embedding.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function output_embedding_exclude_setting( $post ) {
		$indexable = \ElasticPress\Indexables::factory()->get( 'post' );
		if ( ! $indexable->sync_manager->is_post_indexable( $post->ID ) ) {
			return;
		}

		?>
		<div class="misc-pub-section">
			<input id="ep_embedding_exclude" name="ep_embedding_exclude" type="checkbox" value="1" <?php checked( get_post_meta( get_the_ID(), 'ep_embedding_exclude', true ) ); ?>>
			<label for="ep_embedding_exclude"><?php esc_html_e( 'Exclude from vector embeddings', 'elasticpress-labs' ); ?></label>
			<p class="howto">
				<?php if ( 'attachment' === $post->post_type ) : ?>
					<?php esc_html_e( 'Check this if you don\'t want this media to be vectorized.', 'elasticpress-labs' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Check this if you don\'t want this post to be vectorized.', 'elasticpress-labs' ); ?>
				<?php endif; ?>
			</p>
			<?php wp_nonce_field( 'save-embedding-exclude', 'ep-embedding-exclude-nonce' ); ?>
		</div>
		<?php
	}

	/**
	 * Saves exclude from embedding meta.
	 *
	 * @param int $post_id The post ID.
	 */
	public function save_embedding_exclude_meta( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['ep-embedding-exclude-nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ep-embedding-exclude-nonce'] ), 'save-embedding-exclude' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['ep_embedding_exclude'] ) ) {
			update_post_meta( $post_id, 'ep_embedding_exclude', true );
		} else {
			delete_post_meta( $post_id, 'ep_embedding_exclude' );
		}
	}

	/**
	 * Add the content chunks to the index action args.
	 *
	 * This will be picked up by EP.io servers so it is enqueued for processing.
	 *
	 * @param array $args Current index action args.
	 * @param array $post The post being indexed.
	 * @return array
	 */
	public function maybe_add_chunks_to_bulk_index_action_args( array $args, array $post ) {
		if ( ! $this->should_add_vector_field_to_post( $post['ID'] ) ) {
			return $args;
		}

		$post_chunks = $this->get_post_chunks( $post['ID'] );
		if ( 'index_action_args' === $this->get_text_chunks_sending_method( $post_chunks ) ) {
			$args['epio-content-chunks'] = $post_chunks;
		}

		return $args;
	}

	/**
	 * Add text chunks to their field in the post sync args.
	 *
	 * @param array $args Current sync args.
	 * @param int   $post_id Post ID being synced.
	 * @return array
	 */
	public function maybe_add_chunks_to_text_chunks_fields( array $args, int $post_id ) {
		if ( ! $this->should_add_vector_field_to_post( $post_id ) ) {
			return $args;
		}

		$args['ep_embeddings_control'] = [
			'is_processing' => true,
			'errors'        => [],
			'text_chunks'   => [],
		];

		$post_chunks = $this->get_post_chunks( $post_id );
		if ( 'es_doc_field' === $this->get_text_chunks_sending_method( $post_chunks ) ) {
			$args['ep_embeddings_control']['text_chunks'] = $post_chunks;
		}

		return $args;
	}

	/**
	 * Change the doc status indicator depending on the Vector Embeddings process status
	 *
	 * @param array       $status  The status array containing status, message and explanation
	 * @param int         $post_id The post ID being checked
	 * @param array|false $es_doc  The Elasticsearch document
	 * @return array
	 */
	public function maybe_set_doc_status( array $status, int $post_id, $es_doc ): array {
		if ( ! isset( $es_doc['ep_embeddings_control'] ) ) {
			return $status;
		}

		if ( ! empty( $es_doc['ep_embeddings_control']['is_processing'] ) ) {
			$status = [
				'status'      => 'warning',
				'message'     => esc_html__( 'Processing vector embeddings', 'elasticpress-labs' ),
				'explanation' => esc_html__( 'Vector embeddings are still being processed.', 'elasticpress-labs' ),
			];
		}

		if ( ! empty( $es_doc['ep_embeddings_control']['errors'] ) ) {
			$errors_list = '<ul><li>' . implode( '</li><li>', (array) $es_doc['ep_embeddings_control']['errors'] ) . '</li></ul>';
			$status      = [
				'status'      => 'error',
				'message'     => esc_html__( 'Vector embeddings failed', 'elasticpress-labs' ),
				'explanation' => wp_kses_post(
					sprintf(
						// translators: %s is a list of errors.
						esc_html__( 'Vector embeddings failed with the following error(s): %s', 'elasticpress-labs' ),
						$errors_list
					)
				),
			];
		}

		return $status;
	}

	/**
	 * Set the dimensions to -1 for EP.io.
	 *
	 * @return int
	 */
	public function set_epio_dimensions() {
		return -1;
	}

	/**
	 * Add the embedding data to the post vector sync args.
	 *
	 * @param array $args Current sync args.
	 * @param int   $post_id Post ID being synced.
	 * @return array
	 * @throws \Exception If the embedding fails.
	 */
	public function add_vector_field_to_post_sync( array $args, int $post_id ): array {
		if ( ! $this->should_add_vector_field_to_post( $post_id ) ) {
			return $args;
		}

		$chunk_items = $this->get_post_chunk_items( $post_id );

		if ( ! $chunk_items ) {
			return $args;
		}

		$embeddings = $this->feature->get_embedding( $post_id, 'post', wp_list_pluck( $chunk_items, 'text' ) );

		if ( ! is_array( $embeddings ) ) {
			if ( is_wp_error( $embeddings ) ) {
				throw new \Exception( wp_kses_post( $embeddings->get_error_message() ) );
			}
			return $args;
		}

		return $this->add_chunks_field_value( $args, $embeddings, $chunk_items );
	}

	/**
	 * Whether or not we should add the vector field to the post.
	 *
	 * @param int $post_id The Post ID
	 * @return boolean
	 */
	public function should_add_vector_field_to_post( int $post_id ): bool {
		$post = get_post( $post_id );

		$should_add = ! empty( $post ) && $this->settings_page->is_embeddable( $post_id );

		/**
		 * Filter whether the vector field should or not be added to the post.
		 *
		 * @hook ep_embeddings_should_add_vector_field_to_post
		 * @since 2.4.0
		 *
		 * @param {bool} $should_add Whether the vector field should or not be added to the post.
		 * @param {int}  $post_id    The post ID.
		 * @return {bool} The new $should_add value.
		 */
		return apply_filters( 'ep_embeddings_should_add_vector_field_to_post', $should_add, $post_id );
	}

	/**
	 * Return a representation of a post: the text of each chunk to embed.
	 *
	 * By default includes the title, the slug, and the post content, but could also add
	 * meta fields and taxonomy terms, for example.
	 *
	 * @param int $post_id The Post ID
	 * @return array
	 */
	public function get_post_chunks( int $post_id ): array {
		return wp_list_pluck( $this->get_post_chunk_items( $post_id ), 'text' );
	}

	/**
	 * The chunks of a post, with what each one comes from.
	 *
	 * With the `paragraphs` strategy, every chunk starts with a context line: the post
	 * title and the heading path where the chunk starts ("Title › Methodology"), see the
	 * `ep_embeddings_chunk_context` filter.
	 *
	 * @since 2.6.0
	 * @param int $post_id The Post ID
	 * @return array[] `[ 'text' => string, 'type' => 'content'|'taxonomy'|'meta' ]` per chunk.
	 */
	public function get_post_chunk_items( int $post_id ): array {
		/*
		 * Rendering a post's blocks registers Interactivity API state (a chart block can
		 * add its whole dataset). On a page that state is printed once; during a sync it
		 * would pile up for every post until the process runs out of memory. Each post is
		 * rendered with a fresh Interactivity API instance, and the page's one is restored.
		 */
		$has_interactivity = class_exists( '\WP_Interactivity_API' );

		if ( $has_interactivity ) {
			$interactivity               = $GLOBALS['wp_interactivity'] ?? null;
			$GLOBALS['wp_interactivity'] = new \WP_Interactivity_API();
		}

		try {
			return $this->build_post_chunk_items( $post_id );
		} finally {
			if ( $has_interactivity ) {
				$GLOBALS['wp_interactivity'] = $interactivity;
			}
		}
	}

	/**
	 * Build the chunks of a post. See get_post_chunk_items().
	 *
	 * @since 2.6.0
	 * @param int $post_id The Post ID
	 * @return array[]
	 */
	protected function build_post_chunk_items( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return [];
		}

		$sources = [
			'content' => $this->get_main_content( $post ),
		];

		$taxonomies = $this->get_embeddable_taxonomies( $post_id, $post->post_type );
		if ( $taxonomies ) {
			$sources['taxonomy'] = $this->get_post_terms( $post, $taxonomies );
		}

		$meta_fields = $this->get_embeddable_meta( $post_id, $post->post_type );
		if ( $meta_fields ) {
			$sources['meta'] = $this->get_post_meta( $post, $meta_fields );
		}

		$chunk_size   = $this->settings_page->get_chunk_size();
		$overlap_size = $this->settings_page->get_chunk_overlap();
		$items        = [];

		if ( 'words' === $this->feature->get_chunking_strategy() ) {
			foreach ( array_filter( $sources ) as $type => $text ) {
				// Term names and meta values are not post content: their shortcodes must not run.
				foreach ( $this->feature->chunk_content( $text, $chunk_size, $overlap_size, 'content' === $type ) as $chunk ) {
					$items[] = [
						'text' => $chunk,
						'type' => $type,
					];
				}
			}

			return $items;
		}

		$chunker = new Chunker( $chunk_size, $overlap_size );

		// The title is part of each chunk's context when it is one of the embedded fields.
		$title = in_array( 'post_title', $this->settings_page->get_embedding_fields( $post_id ), true )
			? html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' )
			: '';

		foreach ( array_filter( $sources ) as $type => $text ) {
			// Term names and meta values are not post content: their shortcodes must not run.
			$text = $this->feature->normalize_content( $text, 'content' === $type );

			if ( 'content' === $type ) {
				// The title is in every chunk's context line.
				$text = $this->remove_title_section( $text );
			}

			$chunks = 'content' === $type ? $chunker->chunk_sections( $text ) : $chunker->chunk_labelled( $text );

			foreach ( $chunks as $chunk ) {
				$item = $this->prepare_chunk_item( $post, $chunk, $type, $title );

				if ( '' !== trim( $item['text'] ) ) {
					$items[] = $item;
				}
			}
		}

		return $items;
	}

	/**
	 * Add the context line to a chunk and run the chunk filter.
	 *
	 * @since 2.6.0
	 * @param \WP_Post $post  Post.
	 * @param array    $chunk Chunk from the Chunker: `text` and `headings`.
	 * @param string   $type  Chunk type.
	 * @param string   $title Post title for the context line, or empty when it is not embedded.
	 * @return array `text` and `type`.
	 */
	protected function prepare_chunk_item( \WP_Post $post, array $chunk, string $type, string $title ): array {
		$chunk_info = [
			'type'     => $type,
			'headings' => $chunk['headings'],
		];

		/**
		 * Filter the context line parts of a chunk. They are joined with " › " and the
		 * line is added before the chunk text.
		 *
		 * @hook ep_embeddings_chunk_context
		 * @since 2.6.0
		 * @param {array}    $parts      The post title (when it is an embedded field), then the heading path where the chunk starts.
		 * @param {\WP_Post} $post       The post.
		 * @param {array}    $chunk_info `type` (content, taxonomy, meta) and `headings`.
		 * @return {array} New parts. Empty for no context line.
		 */
		$parts = (array) apply_filters( 'ep_embeddings_chunk_context', array_merge( [ $title ], $chunk['headings'] ), $post, $chunk_info );
		$parts = array_filter( array_map( 'trim', array_map( 'strval', $parts ) ), 'strlen' );
		$text  = $parts ? implode( ' › ', $parts ) . "\n" . $chunk['text'] : $chunk['text'];

		/** This filter is documented in includes/classes/Feature/VectorEmbeddings/VectorEmbeddings.php */
		$text = apply_filters( 'ep_embeddings_chunk', $text, $chunk_info );

		return [
			'text' => (string) $text,
			'type' => $type,
		];
	}

	/**
	 * Remove the `# Title` section from normalized main content.
	 *
	 * With the `paragraphs` strategy the title is in every chunk's context line, so it is
	 * not repeated in the text. A post with nothing but a title keeps it, so it still has
	 * a chunk.
	 *
	 * Only the section get_main_content() writes is removed: `# Title` as the first line, up
	 * to the next level 1 line (`# Summary` or `# Content`). Content headings are level 2 or
	 * deeper, so a "# Title" line further down is content, and stays.
	 *
	 * @since 2.6.0
	 * @param string $text Normalized main content.
	 * @return string
	 */
	protected function remove_title_section( string $text ): string {
		$kept    = [];
		$skip    = null;
		$content = false;

		foreach ( Utils\preg_split_safe( '/\R/u', $text ) as $line ) {
			$trimmed = trim( $line );

			if ( '' === $trimmed && null === $skip ) {
				continue;
			}

			if ( null === $skip ) {
				$skip = '# Title' === $trimmed;
			} elseif ( $skip && preg_match( '/^#\s/u', $trimmed ) ) {
				$skip = false;
			}

			if ( $skip ) {
				continue;
			}

			$kept[]  = $line;
			$content = $content || ( '' !== $trimmed && 0 !== strpos( $trimmed, '#' ) );
		}

		return $content ? implode( "\n", $kept ) : $text;
	}

	/**
	 * The title, summary, and content Labs chunks for a post.
	 *
	 * @since 2.6.0
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	protected function get_main_content( \WP_Post $post ): string {
		$post_id = $post->ID;
		$fields  = $this->settings_page->get_embedding_fields( $post_id );

		$main_content = '';

		$title = get_the_title( $post_id );
		if ( $title && in_array( 'post_title', $fields, true ) ) {
			$main_content .= "# Title\n{$title}\n\n";
		}

		if ( ! empty( $post->post_excerpt ) && in_array( 'post_excerpt', $fields, true ) ) {
			$excerpt       = get_the_excerpt( $post_id );
			$main_content .= "# Summary\n{$excerpt}\n\n";
		}

		$content = get_the_content( null, false, $post_id );
		if ( $content && in_array( 'post_content', $fields, true ) ) {
			$main_content .= "# Content\n{$content}\n\n";
		}

		/**
		 * Filter the main content of a post before being split into chunks.
		 *
		 * @hook ep_embeddings_post_main_content
		 * @since 2.4.0
		 *
		 * @param {string}   $main_content Title, excerpt, and content of a post.
		 * @param {\WP_Post} $post         The post being processed.
		 * @return {string} The final main content representation.
		 */
		return (string) apply_filters( 'ep_embeddings_post_main_content', $main_content, $post );
	}

	/**
	 * Return the list of taxonomies that should be included in the post representation.
	 *
	 * @todo use vector embeddings settings page to get the list of taxonomies instead of the weighting.
	 *
	 * @param integer $post_id   The post ID.
	 * @param string  $post_type The post type.
	 * @return array
	 */
	protected function get_embeddable_taxonomies( int $post_id, string $post_type ): array {
		$search_feature = \ElasticPress\Features::factory()->get_registered_feature( 'search' );
		$weighting      = $search_feature->weighting->get_weighting_configuration_with_defaults();
		if ( empty( $weighting[ $post_type ] ) ) {
			/**
			 * Filter the list of taxonomies which terms should be included in the post representation.
			 *
			 * @hook ep_embeddings_post_embeddable_taxonomies
			 * @since 2.4.0
			 *
			 * @param {array}  $embeddable_taxonomies Array of taxonomy names.
			 * @param {int}    $post_id               The post ID.
			 * @param {string} $post_type             The post type.
			 * @return {array} The list of taxonomy names.
			 */
			return apply_filters( 'ep_embeddings_post_embeddable_taxonomies', [], $post_id, $post_type );
		}

		$post_type_weighting = $weighting[ $post_type ];

		$taxonomies = array_reduce(
			array_keys( $post_type_weighting ),
			function ( $acc, $field ) use ( $post_type_weighting ) {
				if ( $post_type_weighting[ $field ]['enabled'] && preg_match( '/terms\.(.*)\.name/', $field, $matches ) ) {
					$acc[] = $matches[1];
				}
				return $acc;
			},
			[]
		);

		// This filter is documented above.
		return apply_filters( 'ep_embeddings_post_embeddable_taxonomies', $taxonomies, $post_id, $post_type );
	}

	/**
	 * Get the representation of the post terms.
	 *
	 * @param \WP_Post $post       The post object
	 * @param array    $taxonomies Taxonomies to be added.
	 * @return string
	 */
	protected function get_post_terms( $post, $taxonomies ): string {
		$post_terms_str = '';
		$post_terms     = [];
		foreach ( $taxonomies as $tax_name ) {
			$terms = get_the_terms( $post, $tax_name );
			if ( is_array( $terms ) ) {
				$post_terms[ $tax_name ] = array_map(
					function ( $term ) {
						return $term->name;
					},
					$terms
				);
			}
		}
		if ( ! empty( $post_terms ) ) {
			$post_terms_str .= "# Taxonomy Terms\n";
			foreach ( $post_terms as $tax_label => $terms ) {
				$post_terms_str .= "## {$tax_label}: ";
				$post_terms_str .= implode( ', ', $terms ) . "\n";
			}
		}

		/**
		 * Filter the string that represents the list of terms associated with this post.
		 *
		 * @hook ep_embeddings_post_terms_str
		 * @since 2.4.0
		 *
		 * @param {string}  $post_terms_str String with post terms.
		 * @param {WP_Post} $post           The post.
		 * @return {string} The string with post terms.
		 */
		return apply_filters( 'ep_embeddings_post_terms_str', $post_terms_str, $post );
	}

	/**
	 * Return the list of metafields that should be included in the post representation.
	 *
	 * @param integer $post_id   The post ID.
	 * @param string  $post_type The post type.
	 * @return array
	 */
	protected function get_embeddable_meta( int $post_id, string $post_type ): array {
		$fields = $this->settings_page->get_embedding_fields( $post_id );

		if ( empty( $fields ) ) {
			/**
			 * Filter the list of metafields which values should be included in the post representation.
			 *
			 * @hook ep_embeddings_post_embeddable_meta
			 * @since 2.4.0
			 *
			 * @param {array}  $embeddable_meta Array of meta keys.
			 * @param {int}    $post_id         The post ID.
			 * @param {string} $post_type       The post type.
			 * @return {array} The list of meta keys.
			 */
			return apply_filters( 'ep_embeddings_post_embeddable_meta', [], $post_id, $post_type );
		}

		$meta_fields = $this->settings_page->get_embedding_fields( $post_id );

		// This filter is documented above.
		return apply_filters( 'ep_embeddings_post_embeddable_meta', $meta_fields, $post_id, $post_type );
	}

	/**
	 * A meta value as text. Arrays and objects become a comma-separated list of their
	 * scalar values (they used to become the word "Array").
	 *
	 * @since 2.6.0
	 * @param mixed $value Meta value.
	 * @param int   $depth Optional. Nesting level, so an object that references itself ends.
	 * @return string
	 */
	protected function meta_value_to_text( $value, int $depth = 0 ): string {
		if ( $depth > 10 ) {
			return '';
		}

		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			$texts = [];

			foreach ( $value as $item ) {
				$texts[] = $this->meta_value_to_text( $item, $depth + 1 );
			}

			return implode( ', ', array_filter( $texts, 'strlen' ) );
		}

		if ( is_bool( $value ) || null === $value ) {
			return '';
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Get the representation of the post meta.
	 *
	 * @param \WP_Post $post        The post object
	 * @param array    $meta_fields List of metafields
	 * @return string
	 */
	protected function get_post_meta( $post, $meta_fields ): string {
		$meta_str = '';
		$values   = [];
		foreach ( $meta_fields as $meta_field ) {
			$values[ $meta_field ] = $this->meta_value_to_text( get_post_meta( $post->ID, $meta_field, true ) );
		}
		$values = array_filter( $values, 'strlen' );

		if ( ! empty( $values ) ) {
			$meta_str .= "# Metadata\n";
			foreach ( $values as $meta_field => $value ) {
				$meta_str .= "## {$meta_field}: {$value}\n";
			}
		}

		/**
		 * Filter the string that represents the meta fields associated with this post.
		 *
		 * @hook ep_embeddings_post_meta_str
		 * @since 2.4.0
		 *
		 * @param {string}  $post_terms_str String with post terms.
		 * @param {WP_Post} $post           The post.
		 * @return {string} The string with post terms.
		 */
		return apply_filters( 'ep_embeddings_post_meta_str', $meta_str, $post );
	}
}
