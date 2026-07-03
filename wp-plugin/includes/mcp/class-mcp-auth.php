<?php
/**
 * MCP WPBakery — bearer authentication + per-token rate limiting for the
 * remote MCP endpoint. On success the request runs as the token's WP user,
 * so every capability check downstream behaves exactly like that user.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCP_WPBakery_MCP_Auth {

	const MAX_PER_MINUTE = 120;

	/** @var MCP_WPBakery_MCP_Tokens */
	private $tokens;

	/** @var object|null */
	private $current = null;

	public function __construct( $tokens = null ) {
		$this->tokens = $tokens ? $tokens : new MCP_WPBakery_MCP_Tokens();
	}

	public function authenticate( $header ) {
		if ( ! preg_match( '/^Bearer\s+(\S+)$/i', trim( (string) $header ), $m ) ) {
			return false;
		}
		$row = $this->tokens->verify( $m[1] );
		if ( ! $row ) {
			return false;
		}
		if ( ! $this->within_rate_limit( (int) $row->id ) ) {
			return false;
		}
		$this->current = $row;
		wp_set_current_user( (int) $row->user_id );
		return true;
	}

	public function token_row() {
		return $this->current;
	}

	private function within_rate_limit( $token_id ) {
		$key   = 'mcpwpb_rl_' . $token_id . '_' . floor( time() / 60 );
		$count = (int) get_transient( $key );
		if ( $count >= self::MAX_PER_MINUTE ) {
			return false;
		}
		set_transient( $key, $count + 1, 60 );
		return true;
	}
}
