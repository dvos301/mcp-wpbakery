<?php
/**
 * MCP WPBakery — HTTP controller for the remote MCP endpoint.
 *
 *   POST /wp-json/mcp-wpbakery/v1/mcp        (Authorization: Bearer wpbmcp_...)
 *
 * Claude Code connects directly:
 *   claude mcp add --transport http wpbakery-<site> <endpoint> \
 *     --header "Authorization: Bearer <token>"
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCP_WPBakery_MCP_Controller {

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'plugins_loaded', array( 'MCP_WPBakery_MCP_Schema', 'maybe_upgrade' ), 20 );
	}

	public function routes() {
		register_rest_route(
			'mcp-wpbakery/v1',
			'/mcp',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true', // bearer auth enforced in handle().
				'callback'            => array( $this, 'handle' ),
			)
		);
	}

	public function handle( $request ) {
		$auth = new MCP_WPBakery_MCP_Auth();
		if ( ! $auth->authenticate( (string) $request->get_header( 'authorization' ) ) ) {
			return new WP_REST_Response(
				MCP_WPBakery_MCP_Server::error( null, -32001, 'Unauthorized: invalid or missing bearer token.' ),
				200
			);
		}

		$body = json_decode( $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( MCP_WPBakery_MCP_Server::error( null, -32700, 'Parse error.' ), 200 );
		}

		$row    = $auth->token_row();
		$server = new MCP_WPBakery_MCP_Server(
			new MCP_WPBakery_MCP_Tools(),
			$row ? (int) $row->id : null
		);
		$out = $server->handle( $body );
		return new WP_REST_Response( empty( $out ) ? null : $out, 200 );
	}
}
