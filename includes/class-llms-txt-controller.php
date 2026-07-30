<?php
/**
 * Llms.txt request routing and response delivery.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

/**
 * Handles the dynamic Llms.txt response.
 */
final class Llms_Txt_Controller {

	/**
	 * Llms.txt generator.
	 *
	 * @var Llms_Txt
	 */
	private $document;

	/**
	 * Constructor.
	 *
	 * @param Llms_Txt $document llms.txt generator.
	 */
	public function __construct( Llms_Txt $document ) {
		$this->document = $document;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( __CLASS__, 'register_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ), 0 );
	}

	/**
	 * Register the root llms.txt endpoint.
	 *
	 * @return void
	 */
	public static function register_rewrite_rule() {
		add_rewrite_rule(
			'^llms\.txt$',
			'index.php?od_ai_content_llms_txt=1',
			'top'
		);
	}

	/**
	 * Register the public query variable.
	 *
	 * @param string[] $query_vars Existing public query variables.
	 * @return string[]
	 */
	public function register_query_vars( $query_vars ) {
		$query_vars[] = 'od_ai_content_llms_txt';

		return $query_vars;
	}

	/**
	 * Send llms.txt when the endpoint is requested.
	 *
	 * @return void
	 */
	public function maybe_serve() {
		if ( ! get_query_var( 'od_ai_content_llms_txt' ) ) {
			return;
		}

		status_header( 200 );
		nocache_headers();

		foreach ( $this->get_response_headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}

		$output = $this->document->generate();

		/**
		 * Fires immediately before llms.txt is sent.
		 *
		 * @since 0.3.0
		 *
		 * @param string $output Generated llms.txt.
		 */
		do_action( 'od_ai_content_before_llms_txt_response', $output );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text is the complete response body.
		echo $output;
		exit;
	}

	/**
	 * Get response headers for the llms.txt endpoint.
	 *
	 * @return string[]
	 */
	public function get_response_headers() {
		return array(
			'Content-Type' => 'text/plain; charset=UTF-8',
		);
	}
}
