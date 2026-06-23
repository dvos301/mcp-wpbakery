<?php
/**
 * Plugin Name:       MCP WPBakery Bridge
 * Plugin URI:        https://github.com/pwd/mcp-wpbakery
 * Description:        Exposes the WPBakery (js_composer) element registry and page content to an MCP server via WP-CLI and REST, so an AI agent can read vc_map and build native, fully-editable WPBakery elements.
 * Version:           0.3.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            PWD
 * License:           GPL-2.0-or-later
 * Text Domain:       mcp-wpbakery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MCP_WPBAKERY_VERSION', '0.3.0' );
define( 'MCP_WPBAKERY_DIR', plugin_dir_path( __FILE__ ) );

require_once MCP_WPBAKERY_DIR . 'includes/class-core.php';
require_once MCP_WPBAKERY_DIR . 'includes/class-rest.php';

// Register REST routes (no-op unless authenticated requests come in).
add_action(
	'plugins_loaded',
	function () {
		( new MCP_WPBakery_REST() )->register();
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
