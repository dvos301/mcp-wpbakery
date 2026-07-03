<?php
/**
 * MCP WPBakery — JSON-RPC server for the remote MCP endpoint.
 *
 * Speaks the MCP protocol (initialize / tools/list / tools/call). The
 * initialize response carries the build instructions: a condensed workflow
 * preamble plus the full WPBAKERY_BUILD_RULES.md packed with the plugin, so
 * every remotely-connected session gets the same contract the local hub
 * injects.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCP_WPBakery_MCP_Server {

	const PROTOCOL = '2024-11-05';

	const PREAMBLE = "Build and edit pages on WordPress + WPBakery. The point is NATIVE, editable elements that reproduce the requested design faithfully.\n\nCORE WORKFLOW:\n1. DISCOVER FIRST: wpbakery_list_elements -> wpbakery_element_schema for params.\n2. NATIVE ELEMENTS ONLY (vc_row / vc_column / vc_custom_heading / vc_column_text / ...). Never lay out with vc_raw_html.\n3. STYLE VIA PAGE CSS (wpbakery_set_page_css / wpbakery_append_page_css) targeting el_class values — never inline HTML, never the css= design-options attr.\n4. SAFE WRITES: wpbakery_validate -> wpbakery_create_page(draft) -> wpbakery_update_page -> wpbakery_render_preview -> fix -> wpbakery_set_status(publish). Every write saves a revision.\n5. ALWAYS wpbakery_render_preview AFTER WRITING and check unrendered_shortcodes — replace anything the theme did not render with a native equivalent.\n6. ITERATE CHEAPLY: wpbakery_replace_in_content for surgical edits, page-CSS tools for styling.";

	/** @var MCP_WPBakery_MCP_Tools */
	private $tools;

	/** @var int|null */
	private $token_id;

	public function __construct( $tools, $token_id = null ) {
		$this->tools    = $tools;
		$this->token_id = $token_id;
	}

	public static function result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	public static function error( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	/**
	 * @param array $req decoded JSON-RPC request.
	 * @return array decoded JSON-RPC response (empty array for notifications).
	 */
	public function handle( $req ) {
		$id     = isset( $req['id'] ) ? $req['id'] : null;
		$method = isset( $req['method'] ) ? (string) $req['method'] : '';
		$params = isset( $req['params'] ) && is_array( $req['params'] ) ? $req['params'] : array();

		switch ( $method ) {
			case 'initialize':
				return self::result(
					$id,
					array(
						'protocolVersion' => self::PROTOCOL,
						'capabilities'    => array( 'tools' => new stdClass() ),
						'serverInfo'      => array(
							'name'    => 'mcp-wpbakery',
							'version' => MCP_WPBAKERY_VERSION,
						),
						'instructions'    => $this->instructions(),
					)
				);

			case 'notifications/initialized':
				return array();

			case 'tools/list':
				return self::result( $id, array( 'tools' => $this->tools->list_for_mcp() ) );

			case 'tools/call':
				return $this->call_tool( $id, $params );

			default:
				return self::error( $id, -32601, 'Method not found: ' . $method );
		}
	}

	private function instructions() {
		$text  = self::PREAMBLE;
		$rules = MCP_WPBAKERY_DIR . 'WPBAKERY_BUILD_RULES.md';
		if ( is_readable( $rules ) ) {
			$text .= "\n\n===== AUTHORITATIVE BUILD RULES (WPBAKERY_BUILD_RULES.md) — READ AND FOLLOW BEFORE BUILDING OR EDITING ANY PAGE =====\n\n"
				. file_get_contents( $rules );
		}
		return $text;
	}

	private function call_tool( $id, $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		$capability = $this->tools->capability_for( $name );
		if ( null === $capability ) {
			return self::error( $id, -32601, 'Unknown tool: ' . $name );
		}
		if ( ! current_user_can( $capability ) ) {
			return self::error( $id, -32003, 'Insufficient capability: ' . $capability );
		}

		$audit   = new MCP_WPBakery_MCP_Audit();
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : null;

		// The REST proxy would otherwise audit as an opaque tool name;
		// record the actual method + route instead.
		$audit_name = 'wpbakery_rest_request' === $name
			? substr( 'rest:' . ( isset( $args['method'] ) ? $args['method'] : 'GET' ) . ' ' . ( isset( $args['route'] ) ? $args['route'] : '' ), 0, 64 )
			: $name;

		try {
			$data = $this->tools->call( $name, $args );
			$audit->record( $this->token_id, get_current_user_id(), $audit_name, $post_id, 'ok' );
			return self::result(
				$id,
				array(
					'content'           => array(
						array(
							'type' => 'text',
							'text' => wp_json_encode( $data ),
						),
					),
					'isError'           => false,
					'structuredContent' => is_array( $data ) ? $data : array( 'value' => $data ),
				)
			);
		} catch ( Exception $e ) {
			// Exceptions carry agent-actionable messages (validation errors,
			// unknown tags, ...) — pass them through, matching the REST bridge.
			$audit->record( $this->token_id, get_current_user_id(), $audit_name, $post_id, 'error' );
			return self::error( $id, -32000, $e->getMessage() );
		} catch ( Throwable $e ) {
			$audit->record( $this->token_id, get_current_user_id(), $audit_name, $post_id, 'fatal' );
			error_log( '[mcp-wpbakery] tool ' . $name . ' failed: ' . $e->getMessage() );
			return self::error( $id, -32000, 'Internal tool error.' );
		}
	}
}
