<?php
/**
 * Thinkery Importer
 *
 * @package Thinkery
 */

/**
 * This is the class for importing things from thinkery.me
 *
 * @since 0.6
 *
 * @package Thinkery
 * @author Alex Kirk
 */

class Thinkery_Importer {
	/**
	 * Contains a reference to the Thinkery class.
	 *
	 * @var Thinkery
	 */
	private $thinkery;

	private $processed_posts = array();

	/**
	 * Constructor
	 *
	 * @param Thinkery $thinkery A reference to the Thinkery object.
	 */
	public function __construct( Thinkery $thinkery ) {
		$this->thinkery = $thinkery;
		add_action( 'admin_init', array( $this, 'register_admin_hooks' ) );
	}

	/**
	 * Register the WordPress hooks
	 */
	public function register_admin_hooks() {
		register_importer( 'thinkery', 'Thinkery', __('Import things from thinkery.me', 'thinkery'), array( $this, 'dispatch' ) );
	}

	public function dispatch() {
		include __DIR__ . '/templates/importer/header.php';

		$step = empty( $_GET['step'] ) ? 0 : (int) $_GET['step'];
		switch ( $step ) {
			case 0:
			include __DIR__ . '/templates/importer/step0.php';
				break;
			case 1:
				check_admin_referer( 'import-upload' );
				$this->handle_upload();
		}

		include __DIR__ . '/templates/importer/footer.php';
	}

	/**
	 * Handles the upload and parses the file
	 *
	 * @return bool False if error uploading or invalid file, true otherwise
	 */
	function handle_upload() {
		$file = wp_import_handle_upload();
		if ( isset( $file['error'] ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'thinkery' ) . '</strong><br />';
			echo esc_html( $file['error'] ) . '</p>';
			return false;
		} else if ( ! file_exists( $file['file'] ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'thinkery' ) . '</strong><br />';
			printf( __( 'The export file could not be found at <code>%s</code>. It is likely that this was caused by a permissions problem.', 'thinkery' ), esc_html( $file['file'] ) );
			echo '</p>';
			return false;
		}

		$parser = new Thinkery_XML_Parser();
		$import_data =  $parser->parse( $file['file'] );
		if ( is_wp_error( $import_data ) ) {
			echo '<p><strong>' . __( 'Sorry, there has been an error.', 'thinkery' ) . '</strong><br />';
			echo esc_html( $import_data->get_error_message() ) . '</p>';
			return false;
		}

		foreach ( $import_data['posts'] as $post ) {
			$post = apply_filters( 'wp_import_post_data_raw', $post );

			if ( isset( $this->processed_posts[ $post['post_id'] ] ) && ! empty( $post['post_id'] ) )
				continue;

			$post_exists = post_exists( $post['post_title'], '', $post['post_date'] );

			if ( $post_exists && get_post_type( $post_exists ) === $post['post_type'] ) {
				// translators: %s is the name of a thing.
				printf( __( 'Thing &#8220;%s&#8221; already exists.', 'thinkery' ), esc_html( $post['post_title'] ) );
				echo '<br />';
				$post_id = $post_exists;
				$this->processed_posts[ intval( $post['post_id'] ) ] = intval( $post_exists );
			} else {
				$original_post_ID = $post['post_id'];
				$post['import_id'] = $original_post_ID;
				$post['post_author'] = (int) get_current_user_id();
				$post['post_status'] = 'private';
				$post_id = wp_insert_post( $post, true );
			}

			$this->processed_posts[$original_post_ID ] = (int) $post_id;

			if ( ! empty( $post['tags'] ) ) {
				$terms_to_set = array();
				foreach ( $post['tags'] as $tag ) {
					if ( empty( $tag ) ) {
						continue;
					}
					$term_exists = term_exists( $tag, Thinkery_Things::TAG );
					$term_id = is_array( $term_exists ) ? $term_exists['term_id'] : $term_exists;
					var_dump($term_id);
					if ( ! $term_id ) {
						$t = wp_insert_term( $tag, Thinkery_Things::TAG, array( 'slug' => $tag ) );
						if ( ! is_wp_error( $t ) ) {
							$term_id = $t['term_id'];
						} else {
							// translators: %s is the name of a tag.
							printf( __( 'Failed to import tag %s', 'thinkery' ), esc_html($tag) );
							if ( defined( 'IMPORT_DEBUG' ) && IMPORT_DEBUG ) {
								echo ': ' . $t->get_error_message();
							}
							echo '<br />';
							continue;
						}
					}
					$terms_to_set[] = intval( $term_id );
				}
				wp_set_post_terms( $post_id, $terms_to_set, Thinkery_Things::TAG );
				unset( $post['tags'], $terms_to_set );
			}
		}
		return true;
	}

}
