<?php
/**
 * Plugin Name:       MCP WPBakery Bridge
 * Plugin URI:        https://github.com/dvos301/mcp-wpbakery
 * Description:        Exposes the WPBakery (js_composer) element registry and page content to an MCP server via WP-CLI and REST, so an AI agent can read vc_map and build native, fully-editable WPBakery elements.
 * Version:           0.8.7
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            PWD
 * License:           GPL-2.0-or-later
 * Text Domain:       mcp-wpbakery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MCP_WPBAKERY_VERSION', '0.8.7' );
define( 'MCP_WPBAKERY_DIR', plugin_dir_path( __FILE__ ) );

require_once MCP_WPBAKERY_DIR . 'includes/class-core.php';
require_once MCP_WPBAKERY_DIR . 'includes/class-rest.php';
require_once MCP_WPBAKERY_DIR . 'includes/class-blocks.php';
require_once MCP_WPBAKERY_DIR . 'includes/class-site-tools.php';
require_once MCP_WPBAKERY_DIR . 'includes/class-element-studio.php';
require_once MCP_WPBAKERY_DIR . 'includes/mcp/class-mcp-schema.php';
require_once MCP_WPBAKERY_DIR . 'includes/mcp/class-mcp-tokens.php';
require_once MCP_WPBAKERY_DIR . 'includes/mcp/class-mcp-audit.php';
require_once MCP_WPBAKERY_DIR . 'includes/mcp/class-mcp-auth.php';
require_once MCP_WPBAKERY_DIR . 'includes/mcp/class-mcp-tools.php';
require_once MCP_WPBAKERY_DIR . 'includes/mcp/class-mcp-server.php';
require_once MCP_WPBAKERY_DIR . 'includes/mcp/class-mcp-controller.php';

// Token + audit tables (created on activation; upgraded on version change,
// because zip-overwrite updates never fire the activation hook).
register_activation_hook( __FILE__, array( 'MCP_WPBakery_MCP_Schema', 'install' ) );

// Re-register the custom-element library through the bridge's PROVEN
// lifecycle (identical hooks/timing to MCP_WPBakery_Blocks, which render
// reliably on Impreza/us-core). The library loads alphabetically BEFORE
// js_composer, and on some stacks its early registrations get wiped by the
// VC/theme init cycle — registering again here (after js_composer) wins.
// The library plugin itself stays standalone; double registration of the
// same tags is harmless (last handler wins).
add_action(
	'plugins_loaded',
	function () {
		$lib_file = WP_PLUGIN_DIR . '/custom-wpbakery-elements/custom-wpbakery-elements.php';
		if ( is_file( $lib_file ) ) {
			include_once $lib_file; // no-op when the library loaded itself.
		}
		if ( class_exists( 'Custom_WPB_Elements_Library_V2' ) ) {
			$lib = new Custom_WPB_Elements_Library_V2();
			$lib->register_shortcodes();
			add_action( 'vc_before_init', array( $lib, 'map_elements' ) );
			add_action( 'init', array( $lib, 'map_elements' ), 4 );
		}
	}
);

// Register REST routes (no-op unless authenticated requests come in).
add_action(
	'plugins_loaded',
	function () {
		( new MCP_WPBakery_REST() )->register();
		// Remote MCP endpoint: Claude Code connects directly with a bearer
		// token — no local server or app password needed.
		( new MCP_WPBakery_MCP_Controller() )->register();
	}
);

// Register the optional mcp_* custom block elements (native, editable, theme-proof).
// These sit ALONGSIDE the stock vc_* / theme elements — nothing is replaced.
add_action(
	'plugins_loaded',
	function () {
		( new MCP_WPBakery_Blocks() )->register();
	}
);

// Tokenized public preview: lets render_preview() expose a draft on the
// front end (themed, fully rendered) for screenshots, without a login session.
add_action(
	'pre_get_posts',
	function ( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['mcp_preview'] ) || empty( $_GET['page_id'] ) ) {
			return;
		}
		$pid   = (int) $_GET['page_id'];
		$token = get_transient( 'mcp_prev_' . $pid );
		if ( $token && hash_equals( (string) $token, (string) wp_unslash( $_GET['mcp_preview'] ) ) ) {
			$query->set( 'post_status', array( 'publish', 'draft', 'pending', 'private', 'future' ) );
		}
	}
);

// Admin status page (visual confirmation; the bridge itself is headless).
if ( is_admin() ) {
	require_once MCP_WPBAKERY_DIR . 'includes/class-admin.php';
	add_action(
		'init',
		function () {
			( new MCP_WPBakery_Admin() )->register();
		}
	);
}

// WP-CLI command — loaded only under WP-CLI.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once MCP_WPBAKERY_DIR . 'includes/class-cli.php';
}
