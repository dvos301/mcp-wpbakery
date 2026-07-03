<?php
/**
 * MCP WPBakery — audit log. Every remote MCP tool call is recorded with the
 * token, user, tool (or method+route for the REST proxy), post and outcome.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCP_WPBakery_MCP_Audit {

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'mcpwpb_audit';
	}

	public function record( $token_id, $user_id, $tool, $post_id, $result ) {
		global $wpdb;
		$wpdb->insert(
			$this->table(),
			array(
				'token_id'   => null === $token_id ? null : (int) $token_id,
				'user_id'    => null === $user_id ? null : (int) $user_id,
				'tool'       => substr( (string) $tool, 0, 64 ),
				'post_id'    => null === $post_id ? null : (int) $post_id,
				'result'     => substr( (string) $result, 0, 16 ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/** @return object[] */
	public function recent( $limit = 50 ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} ORDER BY id DESC LIMIT %d", (int) $limit )
		);
		return is_array( $rows ) ? $rows : array();
	}
}
