<?php
/**
 * MCP WPBakery — core engine.
 *
 * Reads the live vc_map registry, parses WPBakery shortcodes into a tree,
 * serializes a tree back into spec-compliant (fully editable) shortcodes,
 * validates against the registry, and keeps WPBakery's generated custom CSS
 * in sync after programmatic edits.
 *
 * Everything here is transport-agnostic — it is driven by both the WP-CLI
 * command (class-cli.php) and the REST controller (class-rest.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCP_WPBakery_Core {

	/** @var MCP_WPBakery_Core */
	private static $instance;

	/** @var array<string,bool> tag => is the element enclosing (vs self-closing) */
	private $enclosing_cache = null;

	/**
	 * Page-CSS meta keys. WPBakery writes _wpb_post_custom_css, but Impreza's
	 * USBuilder renders from usb_post_custom_css and legacy VC from
	 * vc_post_custom_css. We write all three so CSS actually appears regardless
	 * of which builder/theme renders the page.
	 */
	const PAGE_CSS_KEYS = array( '_wpb_post_custom_css', 'usb_post_custom_css', 'vc_post_custom_css' );

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ------------------------------------------------------------------ */
	/* Capability / environment                                           */
	/* ------------------------------------------------------------------ */

	public function is_vc_active() {
		return defined( 'WPB_VC_VERSION' ) || class_exists( 'Vc_Manager' ) || class_exists( 'WPBMap' );
	}

	public function vc_version() {
		return defined( 'WPB_VC_VERSION' ) ? WPB_VC_VERSION : null;
	}

	/**
	 * Make sure WPBakery has processed its full mapping queue so that
	 * getShortCodes() returns every registered element (core + theme + addons).
	 */
	private function ensure_mapping() {
		if ( function_exists( 'vc_mapper' ) ) {
			$mapper = vc_mapper();
			if ( is_object( $mapper ) ) {
				if ( method_exists( $mapper, 'setInit' ) ) {
					$mapper->setInit( true );
				}
				if ( method_exists( $mapper, 'init' ) ) {
					// Safe to call repeatedly; flushes queued vc_map() activity.
					@$mapper->init();
				}
			}
		}
		if ( class_exists( 'WPBMap' ) && method_exists( 'WPBMap', 'addAllMappedShortcodes' ) ) {
			WPBMap::addAllMappedShortcodes();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Element registry (vc_map)                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Full list of registered elements with their parameter schemas.
	 *
	 * @return array list of normalized element definitions
	 */
	public function get_elements( $summary = false ) {
		$this->require_vc();
		$this->ensure_mapping();

		$codes = WPBMap::getShortCodes();
		if ( ! is_array( $codes ) ) {
			$codes = array();
		}

		$out = array();
		foreach ( $codes as $tag => $settings ) {
			// Resolve lean-mapped elements (their full settings load on demand).
			$full = WPBMap::getShortCode( $tag );
			if ( ! is_array( $full ) || empty( $full ) ) {
				$full = is_array( $settings ) ? $settings : array();
			}
			$out[] = $summary ? $this->summarize_element( $tag, $full ) : $this->normalize_element( $tag, $full );
		}

		// Stable order: by category then name.
		usort(
			$out,
			function ( $a, $b ) {
				$ca = is_array( $a['category'] ) ? implode( ',', $a['category'] ) : (string) $a['category'];
				$cb = is_array( $b['category'] ) ? implode( ',', $b['category'] ) : (string) $b['category'];
				$c  = strcmp( $ca, $cb );
				return 0 !== $c ? $c : strcmp( $a['name'], $b['name'] );
			}
		);

		return $out;
	}

	/**
	 * Full schema for a single element (its parameters, defaults, dependencies).
	 *
	 * @param string $tag shortcode base, e.g. "vc_column_text"
	 * @return array
	 */
	public function get_element( $tag ) {
		$this->require_vc();
		$this->ensure_mapping();

		$full = WPBMap::getShortCode( $tag );
		if ( ! is_array( $full ) || empty( $full ) ) {
			throw new Exception( "Unknown element: {$tag}" );
		}
		return $this->normalize_element( $tag, $full );
	}

	/**
	 * Trim a raw vc_map definition down to the fields an agent needs to build
	 * a valid, editable instance — while keeping every parameter.
	 */
	private function normalize_element( $tag, $def ) {
		$params = array();
		if ( ! empty( $def['params'] ) && is_array( $def['params'] ) ) {
			foreach ( $def['params'] as $p ) {
				if ( ! is_array( $p ) ) {
					continue;
				}
				$param = array(
					'param_name'  => isset( $p['param_name'] ) ? $p['param_name'] : '',
					'type'        => isset( $p['type'] ) ? $p['type'] : 'textfield',
					'heading'     => isset( $p['heading'] ) ? $p['heading'] : '',
					'description' => isset( $p['description'] ) ? wp_strip_all_tags( $p['description'] ) : '',
					'group'       => isset( $p['group'] ) ? $p['group'] : '',
					'admin_label' => ! empty( $p['admin_label'] ),
					'holder'      => isset( $p['holder'] ) ? $p['holder'] : null,
				);
				// Default value (vc_map uses "std" or "value" for the default).
				if ( isset( $p['std'] ) ) {
					$param['default'] = $p['std'];
				} elseif ( isset( $p['value'] ) && ! $this->is_options_list( $p ) ) {
					$param['default'] = $p['value'];
				}
				// Options for dropdown / checkbox / radio params.
				if ( $this->is_options_list( $p ) && isset( $p['value'] ) ) {
					$param['options'] = $this->normalize_options( $p['value'] );
				}
				// Conditional visibility.
				if ( ! empty( $p['dependency'] ) ) {
					$param['dependency'] = $p['dependency'];
				}
				$params[] = $param;
			}
		}

		return array(
			'tag'             => $tag,
			'name'            => isset( $def['name'] ) ? $def['name'] : $tag,
			'base'            => isset( $def['base'] ) ? $def['base'] : $tag,
			'description'     => isset( $def['description'] ) ? wp_strip_all_tags( $def['description'] ) : '',
			'category'        => isset( $def['category'] ) ? $def['category'] : '',
			'icon'            => isset( $def['icon'] ) ? $def['icon'] : '',
			'is_container'    => ! empty( $def['is_container'] ),
			'content_element' => isset( $def['content_element'] ) ? (bool) $def['content_element'] : true,
			'as_parent'       => isset( $def['as_parent'] ) ? $def['as_parent'] : null,
			'as_child'        => isset( $def['as_child'] ) ? $def['as_child'] : null,
			'is_enclosing'    => $this->element_is_enclosing( $def ),
			'default_content' => isset( $def['default_content'] ) ? $def['default_content'] : null,
			'params'          => $params,
		);
	}

	/** Lightweight element record (no params) — keeps list_elements small. */
	private function summarize_element( $tag, $def ) {
		return array(
			'tag'          => $tag,
			'name'         => isset( $def['name'] ) ? $def['name'] : $tag,
			'base'         => isset( $def['base'] ) ? $def['base'] : $tag,
			'category'     => isset( $def['category'] ) ? $def['category'] : '',
			'is_container' => ! empty( $def['is_container'] ),
			'is_enclosing' => $this->element_is_enclosing( $def ),
			'description'  => isset( $def['description'] ) ? wp_strip_all_tags( $def['description'] ) : '',
		);
	}

	private function is_options_list( $param ) {
		$type = isset( $param['type'] ) ? $param['type'] : '';
		return in_array( $type, array( 'dropdown', 'checkbox', 'radio', 'button_group' ), true )
			&& isset( $param['value'] ) && is_array( $param['value'] );
	}

	private function normalize_options( $value ) {
		// vc_map dropdowns are usually [ 'Label' => 'value', ... ] (assoc)
		// or a plain list. Normalize to [ {label, value}, ... ].
		$out = array();
		foreach ( $value as $label => $val ) {
			if ( is_int( $label ) ) {
				$out[] = array(
					'label' => (string) $val,
					'value' => (string) $val,
				);
			} else {
				$out[] = array(
					'label' => (string) $label,
					'value' => (string) $val,
				);
			}
		}
		return $out;
	}

	/**
	 * An element is "enclosing" (uses [tag]...[/tag]) when it is a container
	 * or has an HTML-content param; otherwise it is self-closing ([tag /]).
	 */
	private function element_is_enclosing( $def ) {
		if ( ! empty( $def['is_container'] ) ) {
			return true;
		}
		if ( ! empty( $def['params'] ) && is_array( $def['params'] ) ) {
			foreach ( $def['params'] as $p ) {
				if ( isset( $p['type'] ) && 'textarea_html' === $p['type'] ) {
					return true;
				}
			}
		}
		// vc_row / vc_column / containers without explicit flag.
		$base = isset( $def['base'] ) ? $def['base'] : '';
		if ( in_array( $base, array( 'vc_row', 'vc_row_inner', 'vc_column', 'vc_column_inner', 'vc_section' ), true ) ) {
			return true;
		}
		return false;
	}

	/** Map of tag => is_enclosing, built once per request. */
	private function enclosing_map() {
		if ( null !== $this->enclosing_cache ) {
			return $this->enclosing_cache;
		}
		$this->ensure_mapping();
		$map   = array();
		$codes = class_exists( 'WPBMap' ) ? WPBMap::getShortCodes() : array();
		if ( is_array( $codes ) ) {
			foreach ( $codes as $tag => $settings ) {
				$full        = WPBMap::getShortCode( $tag );
				$def         = ( is_array( $full ) && $full ) ? $full : ( is_array( $settings ) ? $settings : array() );
				$map[ $tag ] = $this->element_is_enclosing( $def );
			}
		}
		$this->enclosing_cache = $map;
		return $map;
	}

	/* ------------------------------------------------------------------ */
	/* Parse / serialize                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Parse a shortcode string into a nested tree.
	 *
	 * Each node: { tag, atts:{}, children:[...] } or { tag, atts, content }.
	 *
	 * @param string     $content
	 * @param array|null $tags    limit to these tags; default = all VC tags
	 * @return array
	 */
	public function parse( $content, $tags = null ) {
		if ( null === $tags ) {
			$this->ensure_mapping();
			$codes = class_exists( 'WPBMap' ) ? WPBMap::getShortCodes() : array();
			$tags  = is_array( $codes ) ? array_keys( $codes ) : array();
		}
		if ( empty( $tags ) ) {
			return array();
		}

		$pattern = get_shortcode_regex( $tags );
		$nodes   = array();

		if ( preg_match_all( '/' . $pattern . '/s', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				// get_shortcode_regex groups: 2=tag, 3=atts, 5=enclosed content.
				$tag  = $m[2];
				$atts = shortcode_parse_atts( $m[3] );
				if ( ! is_array( $atts ) ) {
					$atts = array();
				}
				$node  = array(
					'tag'  => $tag,
					'atts' => $atts,
				);
				$inner    = isset( $m[5] ) ? $m[5] : '';
				$children = $this->parse( $inner, $tags );
				if ( ! empty( $children ) ) {
					$node['children'] = $children;
				} else {
					$text = trim( $inner );
					if ( '' !== $text ) {
						$node['content'] = $text;
					}
				}
				$nodes[] = $node;
			}
		}

		return $nodes;
	}

	/**
	 * Serialize a tree (or a single node) back into shortcodes.
	 *
	 * @param array $nodes list of nodes, or a single node
	 * @return string
	 */
	public function serialize( $nodes ) {
		// Allow passing a single node.
		if ( isset( $nodes['tag'] ) ) {
			$nodes = array( $nodes );
		}
		$enc = $this->enclosing_map();
		$out = '';

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || empty( $node['tag'] ) ) {
				continue;
			}
			$tag    = $node['tag'];
			$atts   = isset( $node['atts'] ) && is_array( $node['atts'] ) ? $node['atts'] : array();
			$attstr = '';
			foreach ( $atts as $k => $v ) {
				if ( is_bool( $v ) ) {
					$v = $v ? 'true' : 'false';
				}
				// WPBakery stores attribute values inside double quotes, encoding
				// any literal double-quote as &quot; (chr(38)='&', avoids entity
				// literals that confuse some editors). Mirrors the VC editor.
				$encoded = str_replace( '"', chr( 38 ) . 'quot;', (string) $v );
				$attstr .= ' ' . $k . '="' . $encoded . '"';
			}

			$inner = '';
			if ( ! empty( $node['children'] ) ) {
				$inner = $this->serialize( $node['children'] );
			} elseif ( isset( $node['content'] ) ) {
				$inner = $node['content'];
			}

			$is_enclosing = isset( $enc[ $tag ] ) ? $enc[ $tag ] : ( '' !== $inner );

			if ( $is_enclosing ) {
				$out .= '[' . $tag . $attstr . ']' . $inner . '[/' . $tag . ']';
			} else {
				$out .= '[' . $tag . $attstr . ']';
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Build / validate                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Build a single, spec-compliant element shortcode (no DB write).
	 *
	 * @param string       $tag
	 * @param array        $atts    attribute => value
	 * @param string|array $inner   raw inner shortcode string, OR a child tree
	 * @return array { shortcode, tree, warnings }
	 */
	public function build_element( $tag, $atts = array(), $inner = '' ) {
		$schema   = $this->get_element( $tag ); // throws if unknown
		$warnings = $this->validate_atts( $schema, is_array( $atts ) ? $atts : array() );

		$node = array(
			'tag'  => $tag,
			'atts' => is_array( $atts ) ? $atts : array(),
		);

		if ( is_array( $inner ) && ! empty( $inner ) ) {
			$node['children'] = isset( $inner['tag'] ) ? array( $inner ) : $inner;
		} elseif ( is_string( $inner ) && '' !== trim( $inner ) ) {
			$node['content'] = $inner;
		} elseif ( '' === trim( (string) $inner ) && ! empty( $schema['default_content'] ) ) {
			$node['content'] = $schema['default_content'];
		}

		return array(
			'shortcode' => $this->serialize( $node ),
			'tree'      => $node,
			'warnings'  => $warnings,
		);
	}

	/**
	 * Validate a full shortcode string against the registry.
	 *
	 * @param string $content
	 * @return array { ok, issues:[ {tag, level, message, param?} ] }
	 */
	public function validate( $content ) {
		$tree   = $this->parse( $content );
		$issues = array();
		$this->walk_validate( $tree, $issues );
		// parse() only sees registered tags, so unknown/typo'd elements slip
		// through as text. Scan the raw content to catch them.
		$this->scan_unknown_tags( $content, $issues );
		$errors = array_filter(
			$issues,
			function ( $i ) {
				return 'error' === $i['level'];
			}
		);
		return array(
			'ok'     => empty( $errors ),
			'issues' => array_values( $issues ),
		);
	}

	/**
	 * Flag bracketed shortcode tags that are not registered anywhere on the
	 * site (they would render as literal text). An enclosed unknown tag
	 * ([x]...[/x]) is a strong signal of intent → error; a bare [x] that could
	 * just be text → warning.
	 */
	private function scan_unknown_tags( $content, &$issues ) {
		$this->ensure_mapping();
		$valid = array();
		if ( class_exists( 'WPBMap' ) ) {
			foreach ( (array) WPBMap::getShortCodes() as $tag => $_ ) {
				$valid[ $tag ] = true;
			}
		}
		$registered = isset( $GLOBALS['shortcode_tags'] ) ? $GLOBALS['shortcode_tags'] : array();
		foreach ( array_keys( $registered ) as $tag ) {
			$valid[ $tag ] = true;
		}

		if ( ! preg_match_all( '/\[([a-zA-Z][a-zA-Z0-9_]*)(?=[\s\]\/])/', $content, $m ) ) {
			return;
		}
		$seen = array();
		foreach ( $m[1] as $tag ) {
			if ( isset( $seen[ $tag ] ) || isset( $valid[ $tag ] ) ) {
				continue;
			}
			$seen[ $tag ] = true;
			$enclosing    = ( false !== strpos( $content, '[/' . $tag . ']' ) );
			$issues[]     = array(
				'tag'     => $tag,
				'level'   => $enclosing ? 'error' : 'warning',
				'message' => $enclosing
					? "Unknown element '{$tag}' (has a [/{$tag}] close tag) is not registered on this site — it would render as literal text."
					: "'{$tag}' looks like a shortcode but is not registered. If it is meant to be plain text, ignore this.",
			);
		}
	}

	private function walk_validate( $nodes, &$issues ) {
		foreach ( $nodes as $node ) {
			$tag = isset( $node['tag'] ) ? $node['tag'] : '';
			try {
				$schema = $this->get_element( $tag );
				foreach ( $this->validate_atts( $schema, isset( $node['atts'] ) ? $node['atts'] : array() ) as $w ) {
					$w['tag']  = $tag;
					$issues[]  = $w;
				}
			} catch ( Exception $e ) {
				$issues[] = array(
					'tag'     => $tag,
					'level'   => 'error',
					'message' => "Unknown element '{$tag}' — not registered in vc_map on this site.",
				);
			}
			if ( ! empty( $node['children'] ) ) {
				$this->walk_validate( $node['children'], $issues );
			}
		}
	}

	/**
	 * Check attributes against an element schema. Unknown params and invalid
	 * dropdown values are warnings (WPBakery is permissive), never hard errors.
	 *
	 * @return array list of { level, message, param }
	 */
	private function validate_atts( $schema, $atts ) {
		$warnings   = array();
		$known      = array();
		$option_map = array();
		foreach ( $schema['params'] as $p ) {
			$known[] = $p['param_name'];
			if ( ! empty( $p['options'] ) ) {
				$option_map[ $p['param_name'] ] = array_map(
					function ( $o ) {
						return $o['value'];
					},
					$p['options']
				);
			}
		}
		// Design-options CSS and common system params are always allowed.
		$system = array( 'css', 'el_class', 'el_id', 'css_animation', 'animation_delay', 'animation_duration' );

		foreach ( $atts as $name => $val ) {
			if ( in_array( $name, $system, true ) ) {
				continue;
			}
			if ( ! in_array( $name, $known, true ) ) {
				$warnings[] = array(
					'level'   => 'warning',
					'param'   => $name,
					'message' => "Param '{$name}' is not defined for '{$schema['tag']}'. WPBakery will keep it but the editor may ignore it.",
				);
				continue;
			}
			if ( isset( $option_map[ $name ] ) && '' !== (string) $val && ! in_array( (string) $val, $option_map[ $name ], true ) ) {
				$allowed    = implode( ', ', $option_map[ $name ] );
				$warnings[] = array(
					'level'   => 'warning',
					'param'   => $name,
					'message' => "Value '{$val}' for '{$name}' is not one of the registered options ({$allowed}).",
				);
			}
		}
		return $warnings;
	}

	/* ------------------------------------------------------------------ */
	/* Read / write posts                                                 */
	/* ------------------------------------------------------------------ */

	public function get_content( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new Exception( "Post {$post_id} not found." );
		}
		return $post->post_content;
	}

	/**
	 * Everything an agent needs about a page in one shot.
	 */
	public function get_post_data( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new Exception( "Post {$post_id} not found." );
		}
		return array(
			'id'         => $post->ID,
			'title'      => $post->post_title,
			'type'       => $post->post_type,
			'status'     => $post->post_status,
			'link'       => get_permalink( $post->ID ),
			'edit_link'  => get_edit_post_link( $post->ID, 'raw' ),
			'uses_vc'    => ( 'true' === get_post_meta( $post->ID, '_wpb_vc_js_status', true ) ),
			'content'    => $post->post_content,
			'structure'  => $this->parse( $post->post_content ),
			'custom_css' => get_post_meta( $post->ID, '_wpb_shortcodes_custom_css', true ),
			'page_css'   => get_post_meta( $post->ID, '_wpb_post_custom_css', true ),
		);
	}

	/**
	 * Write new shortcode content to a post, create a revision (backup),
	 * mark it WPBakery-enabled, and regenerate the design-options custom CSS.
	 *
	 * @param int    $post_id
	 * @param string $content
	 * @param bool   $validate throw on validation errors when true
	 * @return array
	 */
	public function update_post( $post_id, $content, $validate = true, $page_css = null ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new Exception( "Post {$post_id} not found." );
		}

		$validation = $this->validate( $content );
		if ( $validate && ! $validation['ok'] ) {
			throw new Exception(
				'Refusing to write — content has validation errors: ' . wp_json_encode( $validation['issues'] )
			);
		}

		// Snapshot current state as a revision before overwriting.
		if ( function_exists( 'wp_save_post_revision' ) ) {
			wp_save_post_revision( $post_id );
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			throw new Exception( 'wp_update_post failed: ' . $result->get_error_message() );
		}

		// Tell WPBakery this post is built with the page builder.
		update_post_meta( $post_id, '_wpb_vc_js_status', 'true' );

		$css = $this->regenerate_css( $post_id );

		if ( null !== $page_css ) {
			$this->set_page_css( $post_id, $page_css ); // writes all keys + purges
		}

		$purged = $this->purge_caches( $post_id );

		return array(
			'id'         => $post_id,
			'updated'    => true,
			'validation' => $validation,
			'custom_css' => $css,
			'page_css'   => (string) get_post_meta( $post_id, '_wpb_post_custom_css', true ),
			'caches_purged' => $purged,
			'link'       => get_permalink( $post_id ),
		);
	}

	/**
	 * Set the WPBakery "Page Settings → Custom CSS" for a post
	 * (_wpb_post_custom_css). This is the native place for page-scoped CSS, so
	 * native elements can be skinned without adding a Raw HTML element. The CSS
	 * is output in a <style> block on that page and editable in the builder.
	 */
	public function set_page_css( $post_id, $css ) {
		if ( ! get_post( $post_id ) ) {
			throw new Exception( "Post {$post_id} not found." );
		}
		$css = (string) $css;
		foreach ( self::PAGE_CSS_KEYS as $key ) {
			update_post_meta( $post_id, $key, $css );
		}
		$this->purge_caches( $post_id );
		return $css;
	}

	/** Append a rule to the page CSS (all keys) without resending the whole sheet. */
	public function append_page_css( $post_id, $rule ) {
		$current = (string) get_post_meta( $post_id, '_wpb_post_custom_css', true );
		$new     = rtrim( $current ) . "\n" . trim( (string) $rule ) . "\n";
		return $this->set_page_css( $post_id, ltrim( $new ) );
	}

	/**
	 * Render a post's content through the front-end the_content filter so the
	 * theme and its content-elements actually run — the only reliable way to see
	 * what will appear (drafts included). Flags shortcodes that did NOT render
	 * (e.g. vc_btn on Impreza, which survives as literal "[vc_btn]" text), and
	 * returns a tokenized public preview URL for screenshots.
	 */
	public function render_preview( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new Exception( "Post {$post_id} not found." );
		}

		// Tokenized public preview URL (a real themed front-end render, draft-safe).
		$token = wp_generate_password( 20, false );
		set_transient( 'mcp_prev_' . $post_id, $token, 600 );
		$preview_url = add_query_arg(
			array(
				'page_id'     => $post_id,
				'mcp_preview' => $token,
			),
			home_url( '/' )
		);

		// Fetch the real front-end HTML over loopback so what we scan is exactly
		// what a FIRST-TIME (uncached) visitor sees. A query-string buster plus
		// no-cache headers defeat WP Rocket / Varnish, so shortcodes that only
		// register when the page is cached (e.g. vc_icon on Impreza) are caught
		// leaking instead of silently passing. Fall back to the_content if loopback
		// fails.
		$source       = 'loopback';
		$html         = '';
		$loopback_url = add_query_arg( 'mcp_nocache', wp_generate_password( 8, false ), $preview_url );
		$resp         = wp_remote_get(
			$loopback_url,
			array(
				'timeout'   => 25,
				'sslverify' => false,
				'cookies'   => array(),
				'headers'   => array(
					'X-MCP-Preview' => '1',
					'Cache-Control' => 'no-cache',
					'Pragma'        => 'no-cache',
				),
			)
		);
		if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			$html = wp_remote_retrieve_body( $resp );
		}
		if ( '' === $html ) {
			$source = 'filter';
			$html   = apply_filters( 'the_content', $post->post_content );
		}

		// Shortcodes still present as literal text = they did not render. Catch
		// BOTH registered elements the theme skipped (e.g. vc_btn on Impreza)
		// AND unregistered tags that the page's own content uses — a custom
		// element whose plugin failed to load must not pass silently.
		$content_tags = array();
		if ( preg_match_all( '/\[([a-z][a-z0-9_]+)(?=[\s\]\/])/i', (string) $post->post_content, $cm ) ) {
			$content_tags = array_fill_keys( array_map( 'strtolower', array_unique( $cm[1] ) ), true );
		}
		$unrendered = array();
		if ( preg_match_all( '/\[([a-z][a-z0-9_]+)(?=[\s\]\/])/i', $html, $m ) ) {
			$registered = isset( $GLOBALS['shortcode_tags'] ) ? $GLOBALS['shortcode_tags'] : array();
			foreach ( array_unique( $m[1] ) as $tag ) {
				if ( isset( $registered[ $tag ] ) || isset( $content_tags[ strtolower( $tag ) ] ) ) {
					$unrendered[] = $tag;
				}
			}
		}

		// Keep the payload token-safe: return a capped excerpt, not the whole page.
		$excerpt = $html;
		$truncated = false;
		if ( strlen( $excerpt ) > 12000 ) {
			$excerpt   = substr( $excerpt, 0, 12000 );
			$truncated = true;
		}

		return array(
			'post_id'               => $post_id,
			'status'                => $post->post_status,
			'preview_url'           => $preview_url,
			'render_source'         => $source,
			'unrendered_shortcodes' => array_values( $unrendered ),
			'html_bytes'            => strlen( $html ),
			'rendered_excerpt'      => $excerpt,
			'rendered_truncated'    => $truncated,
			'page_css'              => (string) get_post_meta( $post_id, '_wpb_post_custom_css', true ),
		);
	}

	/** Create a new page/post. */
	public function create_page( $title, $slug = '', $status = 'draft', $post_type = 'page' ) {
		$args = array(
			'post_title'  => (string) $title,
			'post_type'   => $post_type,
			'post_status' => $status,
		);
		if ( $slug ) {
			$args['post_name'] = sanitize_title( $slug );
		}
		$id = wp_insert_post( $args, true );
		if ( is_wp_error( $id ) ) {
			throw new Exception( 'create failed: ' . $id->get_error_message() );
		}
		update_post_meta( $id, '_wpb_vc_js_status', 'true' );
		return array(
			'id'        => $id,
			'status'    => get_post_status( $id ),
			'edit_link' => get_edit_post_link( $id, 'raw' ),
			'link'      => get_permalink( $id ),
		);
	}

	/** Set the publish status of a post, or 'trash' it (recoverable from Trash). */
	public function set_status( $post_id, $status ) {
		$allowed = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );
		if ( ! in_array( $status, $allowed, true ) ) {
			throw new Exception( "Invalid status '{$status}'. Allowed: " . implode( ', ', $allowed ) );
		}
		if ( 'trash' === $status ) {
			if ( ! wp_trash_post( (int) $post_id ) ) {
				throw new Exception( 'wp_trash_post failed (already trashed, or invalid id).' );
			}
		} else {
			$res = wp_update_post( array( 'ID' => (int) $post_id, 'post_status' => $status ), true );
			if ( is_wp_error( $res ) ) {
				throw new Exception( $res->get_error_message() );
			}
		}
		$this->purge_caches( $post_id );
		return array(
			'id'     => (int) $post_id,
			'status' => get_post_status( $post_id ),
			'link'   => get_permalink( $post_id ),
		);
	}

	/**
	 * Set a post meta value through WP (so caches invalidate). Pass $is_json to
	 * store an array/object value — needed for e.g. rank_math_robots
	 * (["noindex","nofollow"]).
	 */
	public function set_post_meta( $post_id, $key, $value, $is_json = false ) {
		if ( ! get_post( $post_id ) ) {
			throw new Exception( "Post {$post_id} not found." );
		}
		if ( $is_json ) {
			$decoded = json_decode( (string) $value, true );
			$value   = ( null === $decoded && 'null' !== trim( (string) $value ) ) ? $value : $decoded;
		}
		update_post_meta( $post_id, (string) $key, $value );
		$this->purge_caches( $post_id );
		return array(
			'id'    => (int) $post_id,
			'key'   => $key,
			'value' => get_post_meta( $post_id, $key, true ),
		);
	}

	/** Surgical edit: replace a substring in post_content (avoids resending it all). */
	public function replace_in_content( $post_id, $find, $replace, $expected = null ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new Exception( "Post {$post_id} not found." );
		}
		$count   = substr_count( $post->post_content, $find );
		if ( 0 === $count ) {
			throw new Exception( 'Find string not present in content.' );
		}
		if ( null !== $expected && (int) $expected !== $count ) {
			throw new Exception( "Find string occurs {$count} times, expected {$expected}. Aborting." );
		}
		$new = str_replace( $find, $replace, $post->post_content );
		$r   = wp_update_post( array( 'ID' => (int) $post_id, 'post_content' => $new ), true );
		if ( is_wp_error( $r ) ) {
			throw new Exception( $r->get_error_message() );
		}
		$css    = $this->regenerate_css( $post_id );
		$purged = $this->purge_caches( $post_id );
		return array(
			'id'            => (int) $post_id,
			'replaced'      => $count,
			'custom_css'    => $css,
			'caches_purged' => $purged,
		);
	}

	/**
	 * Bust object + page caches for a post so writes appear immediately. Returns
	 * the list of cache layers it triggered.
	 */
	public function purge_caches( $post_id ) {
		$done = array();
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
			$done[] = 'object';
		}
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
			$done[] = 'wp-rocket';
		} elseif ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$done[] = 'wp-rocket-domain';
		}
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $post_id );
			$done[] = 'w3tc';
		}
		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $post_id );
			$done[] = 'supercache';
		}
		if ( has_action( 'litespeed_purge_post' ) ) {
			do_action( 'litespeed_purge_post', $post_id );
			$done[] = 'litespeed';
		}
		if ( has_action( 'breeze_clear_all_cache' ) ) {
			do_action( 'breeze_clear_all_cache' );
			$done[] = 'breeze';
		}
		// Varnish / generic purgers commonly listen on these.
		do_action( 'clean_post_cache', $post_id, get_post( $post_id ) );
		do_action( 'mcp_wpbakery_purged', $post_id );
		return $done;
	}

	/**
	 * Rebuild the _wpb_shortcodes_custom_css meta from current post content,
	 * so Design Options styling actually renders after a programmatic edit.
	 */
	public function regenerate_css( $post_id ) {
		// Prefer WPBakery's own routine for perfect fidelity.
		if ( function_exists( 'vc_base' ) ) {
			$base = vc_base();
			if ( is_object( $base ) && method_exists( $base, 'save_post_custom_css' ) ) {
				$base->save_post_custom_css( $post_id );
				return (string) get_post_meta( $post_id, '_wpb_shortcodes_custom_css', true );
			}
		}

		// Fallback: extract design-options rules straight from the content.
		$content = get_post_field( 'post_content', $post_id );
		$css     = '';
		if ( preg_match_all( '/css="([^"]+)"/', $content, $m ) ) {
			foreach ( $m[1] as $rule ) {
				if ( false !== strpos( $rule, 'vc_custom_' ) ) {
					$css .= $rule;
				}
			}
		}
		if ( '' !== $css ) {
			update_post_meta( $post_id, '_wpb_shortcodes_custom_css', $css );
		}
		return $css;
	}

	/** List candidate pages/posts for editing. */
	public function list_posts( $args = array() ) {
		$defaults = array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => 100,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		$q    = new WP_Query( array_merge( $defaults, $args ) );
		$rows = array();
		foreach ( $q->posts as $p ) {
			$rows[] = array(
				'id'       => $p->ID,
				'title'    => $p->post_title,
				'type'     => $p->post_type,
				'status'   => $p->post_status,
				'uses_vc'  => ( 'true' === get_post_meta( $p->ID, '_wpb_vc_js_status', true ) ),
				'modified' => $p->post_modified,
				'link'     => get_permalink( $p->ID ),
			);
		}
		return $rows;
	}

	private function require_vc() {
		if ( ! $this->is_vc_active() ) {
			throw new Exception( 'WPBakery Page Builder (js_composer) is not active on this site.' );
		}
	}
}
