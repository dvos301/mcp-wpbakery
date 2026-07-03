<?php
/**
 * MCP WPBakery — database schema for the remote MCP endpoint (tokens + audit).
 *
 * install() runs on activation AND whenever the stored schema version differs
 * from the plugin version (zip-overwrite updates never fire activation hooks).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCP_WPBakery_MCP_Schema {

	const OPTION = 'mcpwpb_db_version';

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$tokens  = $wpdb->prefix . 'mcpwpb_tokens';
		$audit   = $wpdb->prefix . 'mcpwpb_audit';

		dbDelta(
			"CREATE TABLE {$tokens} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				label VARCHAR(191) NOT NULL DEFAULT '',
				token_hash CHAR(64) NOT NULL,
				created_at DATETIME NOT NULL,
				last_used_at DATETIME NULL,
				PRIMARY KEY (id),
				UNIQUE KEY token_hash (token_hash)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$audit} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				token_id BIGINT UNSIGNED NULL,
				user_id BIGINT UNSIGNED NULL,
				tool VARCHAR(64) NOT NULL,
				post_id BIGINT UNSIGNED NULL,
				result VARCHAR(16) NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id)
			) {$charset};"
		);

		update_option( self::OPTION, MCP_WPBAKERY_VERSION, false );
	}

	public static function maybe_upgrade() {
		if ( get_option( self::OPTION ) !== MCP_WPBAKERY_VERSION ) {
			self::install();
		}
	}
}
