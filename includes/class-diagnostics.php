<?php
/**
 * Markdown diagnostics and stored result management.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

use Throwable;
use WP_Post;

/**
 * Diagnoses generated Markdown and stores compact machine-readable results.
 */
final class Diagnostics {

	/**
	 * Diagnostic result post meta key.
	 *
	 * @var string
	 */
	const META_KEY = '_od_ai_content_diagnostics';

	/**
	 * Diagnostic result schema version.
	 *
	 * @var int
	 */
	const RESULT_VERSION = 1;

	/**
	 * Required front matter keys.
	 *
	 * @var string[]
	 */
	const REQUIRED_METADATA = array(
		'title',
		'canonical_url',
		'language',
		'date_published',
		'date_modified',
		'content_type',
	);

	/**
	 * Markdown document generator.
	 *
	 * @var Markdown_Document
	 */
	private $document;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Markdown_Document $document Markdown document generator.
	 * @param Settings          $settings Plugin settings.
	 */
	public function __construct( Markdown_Document $document, Settings $settings ) {
		$this->document = $document;
		$this->settings = $settings;
	}

	/**
	 * Register hooks that invalidate stale stored results.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'save_post', array( $this, 'invalidate_after_save' ), 100, 2 );
		add_action( 'set_object_terms', array( $this, 'invalidate_for_object' ), 10, 1 );
		add_action( 'added_post_meta', array( $this, 'invalidate_after_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'invalidate_after_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'invalidate_after_meta_change' ), 10, 3 );
	}

	/**
	 * Diagnose a post and persist the machine-readable result.
	 *
	 * @param WP_Post $post Post object.
	 * @return array Diagnostic result including generated Markdown.
	 */
	public function diagnose( WP_Post $post ) {
		$checks          = array();
		$markdown        = '';
		$excluded_blocks = array();
		$fallback_blocks = array();

		try {
			$generated       = $this->document->generate_with_report( $post );
			$markdown        = (string) $generated['markdown'];
			$excluded_blocks = array_values( array_unique( (array) $generated['excluded_blocks'] ) );
			$fallback_blocks = array_values( array_unique( (array) $generated['fallback_blocks'] ) );
			$checks[]        = $this->check( 'markdown_generated', 'normal' );
		} catch ( Throwable $error ) {
			/**
			 * Fires when Markdown diagnosis cannot generate a document.
			 *
			 * @since 0.3.0
			 *
			 * @param Throwable $error Generation error.
			 * @param WP_Post   $post  Diagnosed post.
			 */
			do_action( 'od_ai_content_diagnostic_error', $error, $post );
			$checks[] = $this->check( 'markdown_generation_failed', 'error' );
		}

		if ( '' !== $markdown ) {
			$checks = array_merge( $checks, $this->inspect_document( $post, $markdown ) );
		}

		if ( ! empty( $excluded_blocks ) ) {
			$checks[] = $this->check(
				'excluded_blocks',
				'info',
				array( 'blocks' => $excluded_blocks )
			);
		}

		if ( ! empty( $fallback_blocks ) ) {
			$checks[] = $this->check(
				'fallback_blocks',
				'warning',
				array( 'blocks' => $fallback_blocks )
			);
		}

		$result = array(
			'version'           => self::RESULT_VERSION,
			'document_schema'   => Markdown_Document::SCHEMA_VERSION,
			'post_modified_gmt' => $post->post_modified_gmt,
			'diagnosed_at'      => current_time( 'mysql', true ),
			'status'            => $this->get_overall_status( $checks ),
			'checks'            => $checks,
			'excluded_blocks'   => $excluded_blocks,
			'fallback_blocks'   => $fallback_blocks,
		);

		if ( Post_Exclusion::is_excluded( $post->ID ) ) {
			$result['status'] = 'excluded';
		}

		update_post_meta( $post->ID, self::META_KEY, $result );

		$result['markdown'] = $markdown;

		return $result;
	}

	/**
	 * Get a fresh stored result.
	 *
	 * @param WP_Post|int $post Post object or ID.
	 * @return array|null Stored result or null when absent or stale.
	 */
	public function get_stored_result( $post ) {
		$post = $post instanceof WP_Post ? $post : get_post( (int) $post );

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$result = get_post_meta( $post->ID, self::META_KEY, true );

		if (
			! is_array( $result )
			|| self::RESULT_VERSION !== ( isset( $result['version'] ) ? (int) $result['version'] : 0 )
			|| Markdown_Document::SCHEMA_VERSION !== ( isset( $result['document_schema'] ) ? (string) $result['document_schema'] : '' )
			|| ( isset( $result['post_modified_gmt'] ) ? (string) $result['post_modified_gmt'] : '' ) !== $post->post_modified_gmt
		) {
			return null;
		}

		return $result;
	}

	/**
	 * Get the status displayed for a post without generating Markdown.
	 *
	 * @param WP_Post|int $post Post object or ID.
	 * @return string
	 */
	public function get_status( $post ) {
		$post = $post instanceof WP_Post ? $post : get_post( (int) $post );

		if ( ! $post instanceof WP_Post ) {
			return 'not_diagnosed';
		}

		if ( Post_Exclusion::is_excluded( $post->ID ) ) {
			return 'excluded';
		}

		$result = $this->get_stored_result( $post );

		return $result && isset( $result['status'] )
			? sanitize_key( $result['status'] )
			: 'not_diagnosed';
	}

	/**
	 * Add localized labels and messages to a diagnostic result.
	 *
	 * @param array $result Stored or freshly generated result.
	 * @return array
	 */
	public function prepare_result_for_display( array $result ) {
		$status                 = isset( $result['status'] ) ? sanitize_key( $result['status'] ) : 'not_diagnosed';
		$result['status']       = $status;
		$result['status_label'] = self::get_status_label( $status );
		$result['checks']       = array_map( array( $this, 'prepare_check_for_display' ), (array) $result['checks'] );

		return $result;
	}

	/**
	 * Get a localized status label.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function get_status_label( $status ) {
		$labels = array(
			'normal'        => __( 'Normal', 'od-ai-content' ),
			'warning'       => __( 'Warning', 'od-ai-content' ),
			'error'         => __( 'Error', 'od-ai-content' ),
			'excluded'      => __( 'Excluded', 'od-ai-content' ),
			'not_diagnosed' => __( 'Not diagnosed', 'od-ai-content' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['not_diagnosed'];
	}

	/**
	 * Delete a stored result after the post is saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function invalidate_after_save( $post_id, WP_Post $post ) {
		if (
			wp_is_post_revision( $post_id )
			|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| ! in_array( $post->post_type, $this->settings->get_post_types(), true )
		) {
			return;
		}

		$this->invalidate( $post_id );
	}

	/**
	 * Delete a stored result after terms change.
	 *
	 * @param int $object_id Object ID.
	 * @return void
	 */
	public function invalidate_for_object( $object_id ) {
		$post = get_post( (int) $object_id );

		if ( $post instanceof WP_Post && in_array( $post->post_type, $this->settings->get_post_types(), true ) ) {
			$this->invalidate( $post->ID );
		}
	}

	/**
	 * Delete a stored result after post meta changes.
	 *
	 * @param int    $meta_id   Meta ID.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @return void
	 */
	public function invalidate_after_meta_change( $meta_id, $object_id, $meta_key ) {
		unset( $meta_id );

		if ( in_array( $meta_key, array( self::META_KEY, '_edit_lock', '_edit_last' ), true ) ) {
			return;
		}

		$this->invalidate_for_object( $object_id );
	}

	/**
	 * Delete a post's stored diagnostic result.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function invalidate( $post_id ) {
		delete_post_meta( (int) $post_id, self::META_KEY );
	}

	/**
	 * Inspect the generated Markdown document.
	 *
	 * @param WP_Post $post     Source post.
	 * @param string  $markdown Generated Markdown.
	 * @return array[]
	 */
	private function inspect_document( WP_Post $post, $markdown ) {
		$checks = array();

		if ( preg_match( '/\A---\R(.*?)\R---\R/s', $markdown, $front_matter_match ) ) {
			$missing = array();

			foreach ( self::REQUIRED_METADATA as $key ) {
				if ( ! preg_match( '/^' . preg_quote( $key, '/' ) . ':\s*\S+/m', $front_matter_match[1] ) ) {
					$missing[] = $key;
				}
			}

			$checks[] = empty( $missing )
				? $this->check( 'metadata_valid', 'normal' )
				: $this->check( 'metadata_missing', 'error', array( 'fields' => $missing ) );
		} else {
			$checks[] = $this->check(
				'metadata_missing',
				'error',
				array( 'fields' => self::REQUIRED_METADATA )
			);
		}

		$without_front_matter = preg_replace( '/\A---\R.*?\R---\R/s', '', $markdown, 1 );
		$without_front_matter = is_string( $without_front_matter ) ? $without_front_matter : $markdown;
		preg_match_all( '/^#(?!#)\s+(.+?)\s*$/m', $without_front_matter, $h1_matches );
		$h1_count = count( $h1_matches[1] );

		if ( 0 === $h1_count ) {
			$checks[] = $this->check( 'h1_missing', 'error' );
		} elseif ( 1 < $h1_count ) {
			$checks[] = $this->check( 'h1_multiple', 'error', array( 'count' => $h1_count ) );
		} elseif ( trim( wp_strip_all_tags( $h1_matches[1][0] ) ) !== trim( wp_strip_all_tags( get_the_title( $post ) ) ) ) {
			$checks[] = $this->check( 'h1_title_mismatch', 'error' );
		} else {
			$checks[] = $this->check( 'h1_valid', 'normal' );
		}

		$body = preg_replace( '/^#(?!#)[^\r\n]*(?:\R|$)/m', '', $without_front_matter, 1 );
		$body = is_string( $body ) ? trim( $body ) : '';

		$checks[] = '' === $body
			? $this->check( 'body_empty', 'error' )
			: $this->check( 'body_present', 'normal' );

		preg_match_all( '/^(#{1,6})\s+/m', $without_front_matter, $heading_matches );
		$previous_level = 0;
		$jumps          = array();

		foreach ( $heading_matches[1] as $heading_marks ) {
			$level = strlen( $heading_marks );

			if ( 0 < $previous_level && $level > $previous_level + 1 ) {
				$jumps[] = $previous_level . '-' . $level;
			}

			$previous_level = $level;
		}

		$checks[] = empty( $jumps )
			? $this->check( 'heading_hierarchy_valid', 'normal' )
			: $this->check( 'heading_hierarchy_jump', 'warning', array( 'jumps' => array_values( array_unique( $jumps ) ) ) );

		return $checks;
	}

	/**
	 * Create a compact diagnostic check.
	 *
	 * @param string $code     Check code.
	 * @param string $severity Check severity.
	 * @param array  $data     Optional context.
	 * @return array
	 */
	private function check( $code, $severity, array $data = array() ) {
		return array(
			'code'     => sanitize_key( $code ),
			'severity' => sanitize_key( $severity ),
			'data'     => $data,
		);
	}

	/**
	 * Determine the overall result from individual checks.
	 *
	 * @param array[] $checks Diagnostic checks.
	 * @return string
	 */
	private function get_overall_status( array $checks ) {
		$severities = wp_list_pluck( $checks, 'severity' );

		if ( in_array( 'error', $severities, true ) ) {
			return 'error';
		}

		if ( in_array( 'warning', $severities, true ) ) {
			return 'warning';
		}

		return 'normal';
	}

	/**
	 * Add a localized message to one diagnostic check.
	 *
	 * @param array $check Stored diagnostic check.
	 * @return array
	 */
	private function prepare_check_for_display( $check ) {
		$check = is_array( $check ) ? $check : array();
		$code  = isset( $check['code'] ) ? sanitize_key( $check['code'] ) : '';
		$data  = isset( $check['data'] ) && is_array( $check['data'] ) ? $check['data'] : array();

		switch ( $code ) {
			case 'markdown_generated':
				$message = __( 'The Markdown document was generated successfully.', 'od-ai-content' );
				break;
			case 'markdown_generation_failed':
				$message = __( 'The Markdown document could not be generated.', 'od-ai-content' );
				break;
			case 'metadata_valid':
				$message = __( 'Required front matter is present.', 'od-ai-content' );
				break;
			case 'metadata_missing':
				$message = sprintf(
					/* translators: %s: comma-separated metadata field names. */
					__( 'Required front matter is missing: %s', 'od-ai-content' ),
					implode( ', ', isset( $data['fields'] ) ? (array) $data['fields'] : array() )
				);
				break;
			case 'h1_valid':
				$message = __( 'The document has one H1 matching the post title.', 'od-ai-content' );
				break;
			case 'h1_missing':
				$message = __( 'The document H1 is missing.', 'od-ai-content' );
				break;
			case 'h1_multiple':
				$message = __( 'The document has more than one H1.', 'od-ai-content' );
				break;
			case 'h1_title_mismatch':
				$message = __( 'The document H1 does not match the post title.', 'od-ai-content' );
				break;
			case 'body_present':
				$message = __( 'The Markdown body contains content.', 'od-ai-content' );
				break;
			case 'body_empty':
				$message = __( 'The Markdown body is empty.', 'od-ai-content' );
				break;
			case 'heading_hierarchy_valid':
				$message = __( 'The heading hierarchy has no level jumps.', 'od-ai-content' );
				break;
			case 'heading_hierarchy_jump':
				$message = sprintf(
					/* translators: %s: comma-separated heading level transitions, such as 2-4. */
					__( 'The heading hierarchy contains level jumps: %s', 'od-ai-content' ),
					implode( ', ', isset( $data['jumps'] ) ? (array) $data['jumps'] : array() )
				);
				break;
			case 'excluded_blocks':
				$message = sprintf(
					/* translators: %s: comma-separated WordPress block names. */
					__( 'Blocks excluded by the default conversion policy: %s', 'od-ai-content' ),
					implode( ', ', isset( $data['blocks'] ) ? (array) $data['blocks'] : array() )
				);
				break;
			case 'fallback_blocks':
				$message = sprintf(
					/* translators: %s: comma-separated WordPress block names. */
					__( 'Blocks converted through the unverified HTML fallback: %s', 'od-ai-content' ),
					implode( ', ', isset( $data['blocks'] ) ? (array) $data['blocks'] : array() )
				);
				break;
			default:
				$message = __( 'Unknown diagnostic result.', 'od-ai-content' );
		}

		$check['message'] = $message;

		return $check;
	}
}
