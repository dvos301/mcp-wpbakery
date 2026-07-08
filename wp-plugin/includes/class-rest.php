<?php
/**
 * MCP WPBakery — REST controller.
 *
 * Mirrors the WP-CLI command over HTTP for sites where SSH/WP-CLI is not
 * available. Namespace: mcp-wpbakery/v1. Authenticate with an Application
 * Password for a user who can edit_posts (writes require the same).
 *
 *   GET  /wp-json/mcp-wpbakery/v1/ping
 *   GET  /wp-json/mcp-wpbakery/v1/elements
 *   GET  /wp-json/mcp-wpbakery/v1/elements/<tag>
 *   GET  /wp-json/mcp-wpbakery/v1/posts
 *   GET  /wp-json/mcp-wpbakery/v1/posts/<id>
 *   POST /wp-json/mcp-wpbakery/v1/build      { tag, atts, content }
 *   POST /wp-json/mcp-wpbakery/v1/validate   { content }
 *   POST /wp-json/mcp-wpbakery/v1/posts/<id> { content, validate }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCP_WPBakery_REST {

	const NS = 'mcp-wpbakery/v1';

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		$read  = array( $this, 'can_read' );
		$write = array( $this, 'can_write' );

		register_rest_route(
			self::NS,
			'/ping',
			array(
				'methods'             => 'GET',
				'permission_callback' => $read,
				'callback'            => array( $this, 'ping' ),
			)
		);
		register_rest_route(
			self::NS,
			'/elements',
			array(
				'methods'             => 'GET',
				'permission_callback' => $read,
				'callback'            => array( $this, 'elements' ),
			)
		);
		register_rest_route(
			self::NS,
			'/elements/(?P<tag>[a-zA-Z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $read,
				'callback'            => array( $this, 'element' ),
			)
		);
		register_rest_route(
			self::NS,
			'/posts',
			array(
				'methods'             => 'GET',
				'permission_callback' => $read,
				'callback'            => array( $this, 'posts' ),
			)
		);
		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $read,
					'callback'            => array( $this, 'get_post' ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $write,
					'callback'            => array( $this, 'update_post' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/page-css',
			array(
				'methods'             => 'POST',
				'permission_callback' => $write,
				'callback'            => array( $this, 'set_page_css' ),
			)
		);
		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/preview',
			array(
				'methods'             => 'GET',
				'permission_callback' => $read,
				'callback'            => array( $this, 'render_preview' ),
			)
		);
		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/status',
			array(
				'methods'             => 'POST',
				'permission_callback' => $write,
				'callback'            => array( $this, 'set_status' ),
			)
		);
		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/meta',
			array(
				'methods'             => 'POST',
				'permission_callback' => $write,
				'callback'            => array( $this, 'set_meta' ),
			)
		);
		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/replace',
			array(
				'methods'             => 'POST',
				'permission_callback' => $write,
				'callback'            => array( $this, 'replace_in_content' ),
			)
		);
		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/purge',
			array(
				'methods'             => 'POST',
				'permission_callback' => $write,
				'callback'            => array( $this, 'purge' ),
			)
		);
		register_rest_route(
			self::NS,
			'/pages',
			array(
				'methods'             => 'POST',
				'permission_callback' => $write,
				'callback'            => array( $this, 'create_page' ),
			)
		);
		register_rest_route(
			self::NS,
			'/knowledge',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $read,
					'callback'            => array( $this, 'get_knowledge' ),
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $write,
					'callback'            => array( $this, 'update_knowledge' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/build',
			array(
				'methods'             => 'POST',
				'permission_callback' => $read,
				'callback'            => array( $this, 'build' ),
			)
		);
		register_rest_route(
			self::NS,
			'/validate',
			array(
				'methods'             => 'POST',
				'permission_callback' => $read,
				'callback'            => array( $this, 'validate' ),
			)
		);
	}

	public function can_read() {
		return current_user_can( 'edit_posts' );
	}

	public function can_write() {
		return current_user_can( 'edit_posts' );
	}

	private function core() {
		return MCP_WPBakery_Core::instance();
	}

	private function ok( $data ) {
		return new WP_REST_Response(
			array(
				'ok'   => true,
				'data' => $data,
			),
			200
		);
	}

	private function fail( $msg, $code = 400 ) {
		return new WP_REST_Response(
			array(
				'ok'    => false,
				'error' => $msg,
			),
			$code
		);
	}

	public function ping() {
		$c = $this->core();
		return $this->ok(
			array(
				'plugin'     => MCP_WPBAKERY_VERSION,
				'wp'         => get_bloginfo( 'version' ),
				'vc_active'  => $c->is_vc_active(),
				'vc_version' => $c->vc_version(),
				'site_url'   => home_url(),
			)
		);
	}

	public function elements( $req ) {
		try {
			$full = $req && $req->get_param( 'full' );
			return $this->ok( $this->core()->get_elements( ! $full ) );
		} catch ( Throwable $e ) {
			return $this->fail( $e->getMessage() );
		}
	}

	public function element( $req ) {
		try {
			return $this->ok( $this->core()->get_element( $req['tag'] ) );
		} catch ( Throwable $e ) {
			return $this->fail( $e->getMessage(), 404 );
		}
	}

	public function posts( $req ) {
		$args = array();
		if ( $req->get_param( 'post_type' ) ) {
			$args['post_type'] = explode( ',', $req->get_param( 'post_type' ) );
		}
		if ( $req->get_param( 'search' ) ) {
			$args['s'] = $req->get_param( 'search' );
		}
		if ( $req->get_param( 'limit' ) ) {
			$args['posts_per_page'] = (int) $req->get_param( 'limit' );
		}
		try {
			return $this->ok( $this->core()->list_posts( $args ) );
		} catch ( Throwable $e ) {
			return $this->fail( $e->getMessage() );
		}
	}

	public function get_post( $req ) {
		try {
			return $this->ok( $this->core()->get_post_data( (int) $req['id'] ) );
		} catch ( Throwable $e ) {
			return $this->fail( $e->getMessage(), 404 );
		}
	}

	public function update_post( $req ) {
		$body     = $req->get_json_params();
		$content  = isset( $body['content'] ) ? (string) $body['content'] : null;
		$validate = isset( $body['validate'] ) ? (bool) $body['validate'] : true;
		$page_css = array_key_exists( 'page_css', (array) $body ) ? (string) $body['page_css'] : null;
		if ( null === $content ) {
			return $this->fail( 'Missing "content".' );
		}
		try {
			return $this->ok( $this->core()->update_post(
				(int) $req['id'],
				$content,
				$validate,
				$page_css,
				! empty( $body['preview'] )
			) );
		} catch ( Throwable $e ) {
			return $this->fail( $e->getMessage() );
		}
	}

	public function set_page_css( $req ) {
		$body = $req->get_json_params();
		if ( ! isset( $body['css'] ) ) {
			return $this->fail( 'Missing "css".' );
		}
		try {
			$css = (string) $body['css'];
			$id  = (int) $req['id'];
			$out = ! empty( $body['append'] )
				? $this->core()->append_page_css( $id, $css )
				: $this->core()->set_page_css( $id, $css );
			return $this->ok( array( 'page_css' => $out ) );
		} catch ( Throwable $e ) {
			return $this->fail( $e->getMessage() );
		}
	}

	public function render_preview( $req ) {
		try {
			return $this->ok( $this->core()->render_preview( (int) $req['id'], (bool) $req->get_param( 'include_html' ) ) );
		} catch ( Throwable $e ) {
			return $this->fail( $e->getMessage() );
		}
	}

	public function get_knowledge() {
		try {
			return $this->ok( ( new MCP_WPBakery_Site_Knowledge() )->snapshot() );
		} catch ( Throwable $e ) {
			return $this->fail( $e->getMessage() );
		}
	}

	/** POST /knowledge — {note} | {tag, use_instead, note?} | {brand_tokens}. */
	public function update_knowledge( $req ) {
		$body = (array) $req->get_json_params();
		$know = new MCP_WPBakery_Site_Knowledge();
		try {
			if ( ! empty( $body['brand_tokens'] ) ) {
				if ( ! current_user_can( 'manage_options' ) ) {
					return $this->fail( 'brand_tokens requires manage_options.', 403 );
				}
				return $this->ok( $know->set_brand_tokens( $body['brand_tokens'] ) );
			}
			if ( ! empty( $body['tag'] ) ) {
				return $this->ok( $know->flag_broken_element(
					(string) $body['tag'],
					isset( $body['use_instead'] ) ? (string) $body['use_instead'] : '',
					isset( $body['note'] ) ? (string) $body['note'] : ''
				) );
			}
			if ( ! empty( $body['note'] ) ) {
				return $this->ok( $know->add_note( (string) $body['note'], get_current_user_id() ) );
			}
			return $this->fail( 'Provide "note", "tag"+"use_instead", or "brand_tokens".' );
		} catch ( Throwable $e ) {
			return $this->fail( $e->getMessage() );
		}
	}

	public function create_page( $req ) {
		$body = $req->get_json_params();
		if ( empty( $body['title'] ) ) {
			return $this->fail( 'Missing "title".' );
		}
		try {
			return $this->ok( $this->core()->create_page(
				(string) $body['title'],
				isset( $body['slug'] ) ? (string) $body['slug'] : '',
				isset( $body['status'] ) ? (string) $body['status'] : 'draft'
			) );
		} catch ( Throwable $e ) {
			return $this->fail( $e->getMessage() );
		}
	}

	public function set_status( $req ) {
		$body = $req->get_json_params();
		if ( empty( $body['status'] ) ) {
			return $this->fail( 'Missing "status".' );
		}
		try {
			return $this->ok( $this->core()->set_status( (int) $req['id'], (string) $body['status'] ) );
		} catch ( Throwable $e ) {
			return $this->fail( $e->getMessage() );
		}
	}

	public function set_meta( $req ) {
		$body = $req->get_json_params();
		if ( ! isset( $body['key'] ) || ! array_key_exists( 'value', (array) $body ) ) {
			return $this->fail( 'Missing "key" or "value".' );
		}
		try {
			$value = $body['value'];
			$is_json = ! empty( $body['is_json'] );
			// If a JSON value was sent as a real array/object, store it directly.
			if ( is_array( $value ) ) {
				$value   = wp_json_encode( $value );
				$is_json = true;
			}
			return $this->ok( $this->core()->set_post_meta( (int) $req['id'], (string) $body['key'], (string) $value, $is_json ) );
		} catch ( Throwable $e ) {
			return $this->fail( $e->getMessage() );
		}
	}

	public function replace_in_content( $req ) {
		$body = $req->get_json_params();
		if ( ! isset( $body['find'] ) || ! isset( $body['replace'] ) ) {
			return $this->fail( 'Missing "find" or "replace".' );
		}
		try {
			return $this->ok( $this->core()->replace_in_content(
				(int) $req['id'],
				(string) $body['find'],
				(string) $body['replace'],
				isset( $body['expected'] ) ? (int) $body['expected'] : null
			) );
		} catch ( Throwable $e ) {
			return $this->fail( $e->getMessage() );
		}
	}

	public function purge( $req ) {
		try {
			return $this->ok( array( 'caches_purged' => $this->core()->purge_caches( (int) $req['id'] ) ) );
		} catch ( Throwable $e ) {
			return $this->fail( $e->getMessage() );
		}
	}

	public function build( $req ) {
		$body = $req->get_json_params();
		if ( empty( $body['tag'] ) ) {
			return $this->fail( 'Missing "tag".' );
		}
		$atts  = isset( $body['atts'] ) && is_array( $body['atts'] ) ? $body['atts'] : array();
		$inner = isset( $body['content'] ) ? $body['content'] : '';
		try {
			return $this->ok( $this->core()->build_element( $body['tag'], $atts, $inner ) );
		} catch ( Throwable $e ) {
			return $this->fail( $e->getMessage() );
		}
	}

	public function validate( $req ) {
		$body = $req->get_json_params();
		if ( ! isset( $body['content'] ) ) {
			return $this->fail( 'Missing "content".' );
		}
		try {
			return $this->ok( $this->core()->validate( (string) $body['content'] ) );
		} catch ( Throwable $e ) {
			return $this->fail( $e->getMessage() );
		}
	}
}
