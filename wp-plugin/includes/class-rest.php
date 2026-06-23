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

	public function elements() {
		try {
			return $this->ok( $this->core()->get_elements() );
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
			return $this->ok( $this->core()->update_post( (int) $req['id'], $content, $validate, $page_css ) );
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
			return $this->ok( array( 'page_css' => $this->core()->set_page_css( (int) $req['id'], (string) $body['css'] ) ) );
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
