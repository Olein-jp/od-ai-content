<?php
/**
 * Markdown alternative discovery.
 *
 * @package OdAiContent
 */

namespace Olein\OdAiContent;

/**
 * Advertises the Markdown alternative from the canonical HTML response.
 */
final class Discovery {

	/**
	 * Content resolver.
	 *
	 * @var Content_Resolver
	 */
	private $resolver;

	/**
	 * Markdown URL generator.
	 *
	 * @var Markdown_Url
	 */
	private $url;

	/**
	 * Constructor.
	 *
	 * @param Content_Resolver $resolver Content resolver.
	 * @param Markdown_Url     $url      URL generator.
	 */
	public function __construct( Content_Resolver $resolver, Markdown_Url $url ) {
		$this->resolver = $resolver;
		$this->url      = $url;
	}

	/**
	 * Register discovery hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_head', array( $this, 'render_alternate_link' ) );
		add_filter( 'wp_headers', array( $this, 'add_alternate_header' ) );
	}

	/**
	 * Render a link element for eligible singular content.
	 *
	 * @return void
	 */
	public function render_alternate_link() {
		$post = $this->get_queried_post();

		if ( ! $post ) {
			return;
		}

		printf(
			"<link rel=\"alternate\" type=\"text/markdown\" href=\"%s\" />\n",
			esc_url( $this->url->get( $post ) )
		);
	}

	/**
	 * Add an HTTP Link header for eligible singular content.
	 *
	 * @param string[] $headers Response headers.
	 * @return string[]
	 */
	public function add_alternate_header( $headers ) {
		$post = $this->get_queried_post();

		if ( ! $post ) {
			return $headers;
		}

		$alternate = sprintf(
			'<%s>; rel="alternate"; type="text/markdown"',
			esc_url_raw( $this->url->get( $post ) )
		);

		$headers['Link'] = isset( $headers['Link'] )
			? $headers['Link'] . ', ' . $alternate
			: $alternate;

		return $headers;
	}

	/**
	 * Get an eligible queried post.
	 *
	 * @return \WP_Post|null
	 */
	private function get_queried_post() {
		if ( ! is_singular() ) {
			return null;
		}

		$post = get_queried_object();

		return $this->resolver->is_eligible( $post ) ? $post : null;
	}
}
