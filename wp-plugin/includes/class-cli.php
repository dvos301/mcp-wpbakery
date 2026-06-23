<?php
/**
 * MCP WPBakery — WP-CLI command.
 *
 * Exposes the core engine to `wp mcp-wpbakery <subcommand>`. Designed to be
 * driven by the Python MCP server over SSH. Every invocation prints exactly
 * one JSON line: {"ok":true,"data":...} or {"ok":false,"error":"..."}.
 *
 * Large/structured arguments (--atts, --content) are passed base64-encoded to
 * sidestep all shell-quoting problems.
 *
 * Examples:
 *   wp mcp-wpbakery ping
 *   wp mcp-wpbakery elements
 *   wp mcp-wpbakery element vc_btn
 *   wp mcp-wpbakery list --post_type=page
 *   wp mcp-wpbakery get 42
 *   wp mcp-wpbakery structure 42
 *   wp mcp-wpbakery build --tag=vc_btn --atts=<base64-json>
 *   wp mcp-wpbakery validate --content=<base64>
 *   wp mcp-wpbakery update 42 --content=<base64> [--no-validate]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

class MCP_WPBakery_CLI_Command {

	/**
	 * @param array $args       positional args
	 * @param array $assoc_args --flag=value args
	 */
	public function __invoke( $args, $assoc_args ) {
		$sub  = isset( $args[0] ) ? $args[0] : '';
		$core = MCP_WPBakery_Core::instance();

		// Buffer any stray output (PHP notices, plugin echoes) so our JSON
		// line stays clean and parseable on the Python side.
		ob_start();
		try {
			switch ( $sub ) {
				case 'ping':
					$data = array(
						'plugin'     => MCP_WPBAKERY_VERSION,
						'wp'         => get_bloginfo( 'version' ),
						'vc_active'  => $core->is_vc_active(),
						'vc_version' => $core->vc_version(),
						'site_url'   => home_url(),
					);
					break;

				case 'elements':
					$data = $core->get_elements();
					break;

				case 'element':
					if ( empty( $args[1] ) ) {
						throw new Exception( 'Usage: wp mcp-wpbakery element <tag>' );
					}
					$data = $core->get_element( $args[1] );
					break;

				case 'list':
					$qargs = array();
					if ( ! empty( $assoc_args['post_type'] ) ) {
						$qargs['post_type'] = explode( ',', $assoc_args['post_type'] );
					}
					if ( ! empty( $assoc_args['status'] ) ) {
						$qargs['post_status'] = explode( ',', $assoc_args['status'] );
					}
					if ( ! empty( $assoc_args['limit'] ) ) {
						$qargs['posts_per_page'] = (int) $assoc_args['limit'];
					}
					if ( ! empty( $assoc_args['search'] ) ) {
						$qargs['s'] = $this->b64( $assoc_args['search'] );
					}
					$data = $core->list_posts( $qargs );
					break;

				case 'get':
					$data = $core->get_post_data( (int) $this->req_id( $args ) );
					break;

				case 'structure':
					$data = $core->parse( $core->get_content( (int) $this->req_id( $args ) ) );
					break;

				case 'build':
					if ( empty( $assoc_args['tag'] ) ) {
						throw new Exception( 'Usage: wp mcp-wpbakery build --tag=<tag> [--atts=<b64-json>] [--content=<b64>]' );
					}
					$atts  = array();
					if ( ! empty( $assoc_args['atts'] ) ) {
						$atts = json_decode( $this->b64( $assoc_args['atts'] ), true );
						if ( ! is_array( $atts ) ) {
							throw new Exception( '--atts must be base64-encoded JSON object.' );
						}
					}
					$inner = isset( $assoc_args['content'] ) ? $this->b64( $assoc_args['content'] ) : '';
					$data  = $core->build_element( $assoc_args['tag'], $atts, $inner );
					break;

				case 'validate':
					$data = $core->validate( $this->req_content( $assoc_args ) );
					break;

				case 'update':
					$validate = ! isset( $assoc_args['no-validate'] );
					$page_css = isset( $assoc_args['page_css'] ) ? $this->b64( $assoc_args['page_css'] ) : null;
					$data     = $core->update_post(
						(int) $this->req_id( $args ),
						$this->req_content( $assoc_args ),
						$validate,
						$page_css
					);
					break;

				case 'set-page-css':
					if ( ! isset( $assoc_args['css'] ) ) {
						throw new Exception( 'Usage: wp mcp-wpbakery set-page-css <id> --css=<base64>' );
					}
					$data = array(
						'page_css' => $core->set_page_css( (int) $this->req_id( $args ), $this->b64( $assoc_args['css'] ) ),
					);
					break;

				case 'regenerate-css':
					$data = array( 'custom_css' => $core->regenerate_css( (int) $this->req_id( $args ) ) );
					break;

				default:
					throw new Exception(
						"Unknown subcommand '{$sub}'. Try: ping, elements, element, list, get, structure, build, validate, update, set-page-css, regenerate-css"
					);
			}

			$this->emit( true, $data );
		} catch ( Throwable $e ) {
			$this->emit( false, null, $e->getMessage() );
		}
	}

	private function req_id( $args ) {
		if ( empty( $args[1] ) ) {
			throw new Exception( 'Missing post ID.' );
		}
		return $args[1];
	}

	private function req_content( $assoc_args ) {
		if ( ! isset( $assoc_args['content'] ) ) {
			throw new Exception( 'Missing --content=<base64> argument.' );
		}
		return $this->b64( $assoc_args['content'] );
	}

	private function b64( $val ) {
		$decoded = base64_decode( $val, true );
		return false === $decoded ? '' : $decoded;
	}

	private function emit( $ok, $data = null, $error = null ) {
		// Drop any buffered stray output before printing the JSON envelope.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		$payload = $ok
			? array(
				'ok'   => true,
				'data' => $data,
			)
			: array(
				'ok'    => false,
				'error' => $error,
			);
		// Single line, sentinel-wrapped so the client can locate it reliably.
		fwrite( STDOUT, "\n<<<MCPWPB>>>" . wp_json_encode( $payload ) . "<<<END>>>\n" );
		WP_CLI::halt( $ok ? 0 : 1 );
	}
}

WP_CLI::add_command( 'mcp-wpbakery', 'MCP_WPBakery_CLI_Command' );
