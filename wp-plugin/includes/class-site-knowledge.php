<?php
/**
 * MCP WPBakery — persistent per-site knowledge.
 *
 * The expensive part of an agent's first page build is learning the site:
 * brand tokens, which elements the theme silently breaks, media quirks.
 * This class makes that learning durable — stored in one option, injected
 * into every MCP session via initialize instructions, and extendable by
 * the agents themselves (add_note / flag_broken_element / set_brand_tokens).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCP_WPBakery_Site_Knowledge {

	const OPTION       = 'mcpwpb_site_knowledge';
	const MAX_NOTES    = 100;
	const MAX_NOTE_LEN = 500;
	const MAX_TOKENS   = 80;

	/* ---- storage ------------------------------------------------------ */

	private function stored() {
		$data = get_option( self::OPTION );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		return array_merge(
			array(
				'brand_tokens'    => array(),
				'broken_elements' => array(),
				'notes'           => array(),
			),
			$data
		);
	}

	private function save( $data ) {
		update_option( self::OPTION, $data );
	}

	/* ---- reads --------------------------------------------------------- */

	/** Everything an agent should know about this site, one call. */
	public function snapshot() {
		$theme = wp_get_theme();
		return array(
			'theme'           => array(
				'name'     => $theme->get( 'Name' ),
				'template' => $theme->get_template(),
				'version'  => $theme->get( 'Version' ),
				'parent'   => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
			),
			'brand'           => $this->brand(),
			'broken_elements' => $this->broken_elements(),
			'notes'           => $this->stored()['notes'],
		);
	}

	/**
	 * Brand tokens: explicit overrides win, otherwise best-effort extraction
	 * from the theme's own options (Impreza/us-core stores fonts + colours in
	 * the us_theme_options option; other themes fall back to theme mods).
	 */
	public function brand() {
		$stored   = $this->stored();
		$detected = array();

		$source = get_option( 'us_theme_options' );
		$from   = 'us_theme_options';
		if ( ! is_array( $source ) || empty( $source ) ) {
			$source = get_theme_mods();
			$from   = 'theme_mods';
		}
		if ( is_array( $source ) ) {
			foreach ( $source as $key => $value ) {
				if ( ! is_scalar( $value ) || '' === (string) $value ) {
					continue;
				}
				if ( preg_match( '/color|font/i', (string) $key ) ) {
					$detected[ (string) $key ] = (string) $value;
				}
				if ( count( $detected ) >= self::MAX_TOKENS ) {
					break;
				}
			}
		}

		$logo_id  = (int) get_theme_mod( 'custom_logo' );
		$logo_url = $logo_id ? wp_get_attachment_url( $logo_id ) : null;

		return array(
			'source'    => $from,
			'logo_url'  => $logo_url,
			'detected'  => $detected,
			'overrides' => $stored['brand_tokens'],
		);
	}

	/**
	 * Elements known not to render on this stack: theme-profile defaults
	 * (rediscovering these cost real sessions real time) merged with
	 * agent-flagged additions.
	 */
	public function broken_elements() {
		$defaults = array();
		if ( $this->is_us_core() ) {
			$defaults = array(
				'vc_btn'          => 'Can render as literal [vc_btn] text on Impreza/us-core (especially uncached). Use a styled <a class="btn"> inside vc_column_text, skinned via page CSS.',
				'vc_icon'         => 'Can render as literal [vc_icon] text on Impreza/us-core. Use a CSS background-SVG (data:image/svg+xml) on a native element instead.',
				'vc_single_image' => 'Silently does not render on Impreza/us-core builds. Use us_image (attach_image accepts an attachment ID) and verify with render_preview.',
			);
		}
		$stored = $this->stored();
		return array_merge( $defaults, $stored['broken_elements'] );
	}

	private function is_us_core() {
		if ( defined( 'US_CORE_VERSION' ) || function_exists( 'us_get_option' ) ) {
			return true;
		}
		$theme = wp_get_theme();
		return false !== stripos( $theme->get_template() . ' ' . (string) $theme->get( 'Name' ), 'impreza' );
	}

	/* ---- writes (agent-facing) ----------------------------------------- */

	public function add_note( $text, $user_id = 0 ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		if ( '' === $text ) {
			throw new InvalidArgumentException( 'note must be a non-empty string.' );
		}
		$text = substr( $text, 0, self::MAX_NOTE_LEN );

		$data = $this->stored();
		foreach ( $data['notes'] as $existing ) {
			if ( isset( $existing['text'] ) && $existing['text'] === $text ) {
				return array( 'added' => false, 'reason' => 'duplicate', 'notes' => count( $data['notes'] ) );
			}
		}
		$user            = get_userdata( (int) $user_id );
		$data['notes'][] = array(
			'text' => $text,
			'by'   => $user ? $user->user_login : 'unknown',
			'at'   => gmdate( 'Y-m-d' ),
		);
		// Keep the newest MAX_NOTES.
		$data['notes'] = array_slice( $data['notes'], -self::MAX_NOTES );
		$this->save( $data );
		return array( 'added' => true, 'notes' => count( $data['notes'] ) );
	}

	public function flag_broken_element( $tag, $use_instead, $note = '' ) {
		$tag = sanitize_key( (string) $tag );
		if ( '' === $tag ) {
			throw new InvalidArgumentException( 'tag must be a shortcode tag, e.g. vc_single_image.' );
		}
		$advice = trim( wp_strip_all_tags( (string) $use_instead ) );
		if ( '' === $advice ) {
			throw new InvalidArgumentException( 'use_instead must say what to use instead.' );
		}
		$note = trim( wp_strip_all_tags( (string) $note ) );
		$text = 'Use instead: ' . $advice . ( '' !== $note ? ' — ' . $note : '' );

		$data                             = $this->stored();
		$data['broken_elements'][ $tag ] = substr( $text, 0, self::MAX_NOTE_LEN );
		$this->save( $data );
		return array( 'flagged' => $tag, 'broken_elements' => count( $data['broken_elements'] ) );
	}

	public function set_brand_tokens( $tokens ) {
		if ( ! is_array( $tokens ) ) {
			throw new InvalidArgumentException( 'tokens must be an object of name => value.' );
		}
		$data = $this->stored();
		foreach ( $tokens as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( null === $value || '' === $value ) {
				unset( $data['brand_tokens'][ $key ] ); // empty value deletes the override.
				continue;
			}
			if ( is_scalar( $value ) ) {
				$data['brand_tokens'][ $key ] = substr( (string) $value, 0, 200 );
			}
		}
		$data['brand_tokens'] = array_slice( $data['brand_tokens'], -self::MAX_TOKENS, null, true );
		$this->save( $data );
		return array( 'brand_tokens' => $data['brand_tokens'] );
	}

	/* ---- initialize injection ------------------------------------------ */

	/**
	 * Compact text block for MCP initialize instructions. Only emits sections
	 * that have content; hard-capped so a note-happy agent can't blow up the
	 * session preamble.
	 */
	public function instructions_text( $max_chars = 4000 ) {
		$snap  = $this->snapshot();
		$lines = array();

		$theme   = $snap['theme'];
		$lines[] = 'Theme: ' . $theme['name'] . ' ' . $theme['version']
			. ( $theme['parent'] ? ' (child of ' . $theme['parent'] . ')' : '' );

		$brand = array_merge( $snap['brand']['detected'], $snap['brand']['overrides'] );
		if ( ! empty( $brand ) ) {
			$lines[] = '';
			$lines[] = 'BRAND TOKENS (' . $snap['brand']['source'] . ( $snap['brand']['overrides'] ? ' + overrides' : '' ) . '):';
			foreach ( $brand as $key => $value ) {
				$lines[] = '  ' . $key . ': ' . $value;
			}
		}
		if ( ! empty( $snap['brand']['logo_url'] ) ) {
			$lines[] = '  logo: ' . $snap['brand']['logo_url'];
		}

		if ( ! empty( $snap['broken_elements'] ) ) {
			$lines[] = '';
			$lines[] = 'KNOWN-BROKEN ELEMENTS on this site (do NOT use; no need to rediscover):';
			foreach ( $snap['broken_elements'] as $tag => $advice ) {
				$lines[] = '  ' . $tag . ' — ' . $advice;
			}
		}

		if ( ! empty( $snap['notes'] ) ) {
			$lines[] = '';
			$lines[] = 'NOTES from prior sessions (newest last):';
			foreach ( array_slice( $snap['notes'], -30 ) as $note ) {
				$lines[] = '  [' . $note['at'] . '] ' . $note['text'];
			}
		}

		$text = implode( "\n", $lines );
		if ( strlen( $text ) > $max_chars ) {
			$text = substr( $text, 0, $max_chars ) . "\n  [... truncated — call wpbakery_get_site_knowledge for the rest]";
		}
		return $text;
	}
}
