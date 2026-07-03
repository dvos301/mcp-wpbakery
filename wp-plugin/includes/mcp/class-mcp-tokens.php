<?php
/**
 * MCP WPBakery — bearer token store for the remote MCP endpoint.
 *
 * Tokens are SHA-256 hashed at rest; the plaintext is shown exactly once at
 * issuance. Verification is a hash lookup against a unique index.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCP_WPBakery_MCP_Tokens {

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'mcpwpb_tokens';
	}

	/**
	 * @return array { id, token } — plaintext token, shown once only.
	 */
	public function issue( $user_id, $label ) {
		global $wpdb;
		$token = 'wpbmcp_' . bin2hex( random_bytes( 24 ) );
		$wpdb->insert(
			$this->table(),
			array(
				'user_id'    => (int) $user_id,
				'label'      => substr( (string) $label, 0, 191 ),
				'token_hash' => hash( 'sha256', $token ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
		return array(
			'id'    => (int) $wpdb->insert_id,
			'token' => $token,
		);
	}

	/** @return object|null token row on success. */
	public function verify( $token ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, user_id, label, last_used_at FROM {$this->table()} WHERE token_hash = %s",
				hash( 'sha256', (string) $token )
			)
		);
		if ( ! $row ) {
			return null;
		}
		$wpdb->update(
			$this->table(),
			array( 'last_used_at' => current_time( 'mysql', true ) ),
			array( 'id' => $row->id )
		);
		return $row;
	}

	public function revoke( $id ) {
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'id' => (int) $id ) );
	}

	/** @return object[] */
	public function all() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, user_id, label, created_at, last_used_at FROM {$this->table()} ORDER BY id DESC" );
		return is_array( $rows ) ? $rows : array();
	}
}
