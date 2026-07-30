<?php
/**
 * Markdown request routing and response delivery.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

/**
 * Handles dynamic index.html.md responses.
 */
final class Response_Controller {

	/**
	 * Content resolver.
	 *
	 * @var Content_Resolver
	 */
	private $resolver;

	/**
	 * Markdown document generator.
	 *
	 * @var Markdown_Document
	 */
	private $document;

	/**
	 * Markdown URL generator.
	 *
	 * @var Markdown_Url
	 */
	private $url;

	/**
	 * Constructor.
	 *
	 * @param Content_Resolver  $resolver Content resolver.
	 * @param Markdown_Document $document Document generator.
	 * @param Markdown_Url      $url      URL generator.
	 */
	public function __construct( Content_Resolver $resolver, Markdown_Document $document, Markdown_Url $url ) {
		$this->resolver = $resolver;
		$this->document = $document;
		$this->url      = $url;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( __CLASS__, 'register_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_markdown' ), 0 );
	}

	/**
	 * Register the Markdown endpoint rewrite rule.
	 *
	 * @return void
	 */
	public static function register_rewrite_rule() {
		add_rewrite_rule(
			'^(.+?)/index\.html\.md/?$',
			'index.php?od_ai_content_markdown=1&od_ai_content_path=$matches[1]',
			'top'
		);
	}

	/**
	 * Register public query variables.
	 *
	 * @param string[] $query_vars Existing public query variables.
	 * @return string[]
	 */
	public function register_query_vars( $query_vars ) {
		$query_vars[] = 'od_ai_content_markdown';
		$query_vars[] = 'od_ai_content_path';

		return $query_vars;
	}

	/**
	 * Send a Markdown response when the endpoint is requested.
	 *
	 * @return void
	 */
	public function maybe_serve_markdown() {
		if ( ! get_query_var( 'od_ai_content_markdown' ) ) {
			return;
		}

		$post = $this->resolver->resolve_path( get_query_var( 'od_ai_content_path' ) );

		if ( ! $post ) {
			$this->send_not_found();
		}

		$markdown  = $this->document->generate( $post );
		$language  = get_bloginfo( 'language' );
		$canonical = get_permalink( $post );

		status_header( 200 );
		header( 'Content-Type: text/markdown; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		header( 'Content-Language: ' . sanitize_text_field( $language ) );
		header( 'Link: <' . esc_url_raw( $canonical ) . '>; rel="canonical"' );
		header( 'X-Robots-Tag: noindex' );

		/**
		 * Fires immediately before a Markdown document is sent.
		 *
		 * @since 0.1.0
		 *
		 * @param \WP_Post $post      Post being served.
		 * @param string   $markdown  Generated Markdown.
		 * @param string   $url       Markdown alternative URL.
		 */
		do_action( 'od_ai_content_before_response', $post, $markdown, $this->url->get( $post ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markdown is the complete non-HTML response body.
		echo $markdown;
		exit;
	}

	/**
	 * Send a non-cacheable plain-text 404 response.
	 *
	 * @return void
	 */
	private function send_not_found() {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		echo esc_html__( 'Markdown content not found.', 'od-ai-content' );
		exit;
	}
}
