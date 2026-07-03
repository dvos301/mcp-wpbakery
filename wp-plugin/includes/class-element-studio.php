<?php
/**
 * MCP WPBakery — Element Studio.
 *
 * Authors reusable custom WPBakery elements. Definitions are NOT stored in
 * this plugin: they are written into a separate, standalone library plugin
 * (wp-content/plugins/custom-wpbakery-elements/) generated from
 * templates/custom-elements-loader.php. That library owns the elements —
 * deleting the MCP WPBakery Bridge leaves every custom element rendering
 * and editable in the WPBakery editor.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCP_WPBakery_Element_Studio {

	const LIB_SLUG = 'custom-wpbakery-elements';
	const LIB_FILE = 'custom-wpbakery-elements/custom-wpbakery-elements.php';

	/** Never let a custom tag shadow builder/theme namespaces. */
	const RESERVED_PREFIXES = array( 'vc_', 'us_', 'vc-', 'wp_' );

	private function lib_dir() {
		return trailingslashit( WP_PLUGIN_DIR ) . self::LIB_SLUG . '/';
	}

	private function elements_dir() {
		return $this->lib_dir() . 'elements/';
	}

	/**
	 * Create the standalone library plugin (or refresh its loader when this
	 * bridge ships a newer one), and make sure it is active.
	 */
	public function ensure_library() {
		$source = MCP_WPBAKERY_DIR . 'templates/custom-elements-loader.php';
		if ( ! is_file( $source ) ) {
			throw new RuntimeException( 'Loader template missing from the bridge plugin (templates/custom-elements-loader.php).' );
		}
		if ( ! is_dir( $this->elements_dir() ) && ! wp_mkdir_p( $this->elements_dir() ) ) {
			throw new RuntimeException( 'Could not create ' . $this->elements_dir() . ' — check filesystem permissions.' );
		}

		$target = $this->lib_dir() . 'custom-wpbakery-elements.php';
		if ( $this->loader_version( $source ) !== $this->loader_version( $target ) ) {
			if ( false === file_put_contents( $target, file_get_contents( $source ) ) ) { // phpcs:ignore
				throw new RuntimeException( 'Could not write the library loader — check filesystem permissions.' );
			}
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! is_plugin_active( self::LIB_FILE ) ) {
			$err = activate_plugin( self::LIB_FILE );
			if ( is_wp_error( $err ) ) {
				throw new RuntimeException( 'Library created but activation failed: ' . $err->get_error_message() );
			}
		}
	}

	private function loader_version( $file ) {
		if ( ! @is_file( $file ) ) { // phpcs:ignore
			return null;
		}
		$head = (string) file_get_contents( $file, false, null, 0, 2048 );
		return preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)/mi', $head, $m ) ? $m[1] : null;
	}

	/**
	 * Create (or with $overwrite, update) a custom element definition.
	 *
	 * @param array $args tag, name, template (+ description, category, params, css)
	 */
	public function save( $args, $overwrite = false ) {
		$tag = isset( $args['tag'] ) ? strtolower( trim( (string) $args['tag'] ) ) : '';
		if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', $tag ) ) {
			throw new InvalidArgumentException( 'tag must be lowercase letters/digits/underscores, starting with a letter (e.g. "pwd_feature_grid").' );
		}
		foreach ( self::RESERVED_PREFIXES as $prefix ) {
			if ( 0 === strpos( $tag, $prefix ) ) {
				throw new InvalidArgumentException( 'tag must not start with the reserved prefix "' . $prefix . '" — use a site prefix like "pwd_".' );
			}
		}
		$name     = isset( $args['name'] ) ? sanitize_text_field( (string) $args['name'] ) : '';
		$template = isset( $args['template'] ) ? (string) $args['template'] : '';
		if ( '' === $name || '' === trim( $template ) ) {
			throw new InvalidArgumentException( 'name and template are required.' );
		}

		$params = array();
		foreach ( (array) ( isset( $args['params'] ) ? $args['params'] : array() ) as $p ) {
			if ( ! is_array( $p ) || empty( $p['param_name'] ) || empty( $p['type'] ) ) {
				throw new InvalidArgumentException( 'Every param needs at least "type" and "param_name" (vc_map param shape).' );
			}
			if ( empty( $p['heading'] ) ) {
				$p['heading'] = ucwords( str_replace( '_', ' ', $p['param_name'] ) );
			}
			$params[] = $p;
		}
		// Enclosing elements: the standard WPBakery trick is a param named
		// "content" — the editor maps it to the inner content automatically.
		if ( false !== strpos( $template, '{{content}}' ) && ! $this->has_param( $params, 'content' ) ) {
			$params[] = array(
				'type'       => 'textarea_html',
				'heading'    => 'Content',
				'param_name' => 'content',
			);
		}

		$this->ensure_library();

		$file   = $this->elements_dir() . $tag . '.json';
		$exists = is_file( $file );
		if ( $exists && ! $overwrite ) {
			throw new InvalidArgumentException( 'Element "' . $tag . '" already exists — use wpbakery_update_custom_element to change it.' );
		}

		$def = array(
			'tag'         => $tag,
			'name'        => $name,
			'description' => isset( $args['description'] ) ? sanitize_text_field( (string) $args['description'] ) : '',
			'category'    => isset( $args['category'] ) && '' !== $args['category'] ? sanitize_text_field( (string) $args['category'] ) : 'Custom Elements',
			'params'      => $params,
			'template'    => $template,
			'css'         => isset( $args['css'] ) ? (string) $args['css'] : '',
			'updated_at'  => current_time( 'mysql', true ) . ' UTC',
		);
		if ( false === file_put_contents( $file, wp_json_encode( $def, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) { // phpcs:ignore
			throw new RuntimeException( 'Could not write ' . $file );
		}

		return array(
			'tag'      => $tag,
			'name'     => $name,
			'action'   => $exists ? 'updated' : 'created',
			'library'  => self::LIB_FILE,
			'file'     => str_replace( WP_PLUGIN_DIR, '', $file ),
			'params'   => count( $params ),
			'note'     => 'Registered in vc_map from the next request onward (each MCP call is its own request, so it is immediately usable). The element lives in the standalone "' . self::LIB_SLUG . '" plugin and survives removal of this bridge.',
		);
	}

	public function all() {
		$out = array();
		if ( ! is_dir( $this->elements_dir() ) ) {
			return array(
				'library_installed' => false,
				'elements'          => array(),
			);
		}
		foreach ( (array) glob( $this->elements_dir() . '*.json' ) as $file ) {
			$def = json_decode( (string) file_get_contents( $file ), true );
			if ( ! is_array( $def ) || empty( $def['tag'] ) ) {
				continue;
			}
			$out[] = array(
				'tag'         => $def['tag'],
				'name'        => isset( $def['name'] ) ? $def['name'] : '',
				'category'    => isset( $def['category'] ) ? $def['category'] : '',
				'description' => isset( $def['description'] ) ? $def['description'] : '',
				'params'      => count( isset( $def['params'] ) ? (array) $def['params'] : array() ),
				'has_css'     => ! empty( $def['css'] ),
				'enclosing'   => false !== strpos( (string) ( isset( $def['template'] ) ? $def['template'] : '' ), '{{content}}' ),
				'updated_at'  => isset( $def['updated_at'] ) ? $def['updated_at'] : null,
			);
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		return array(
			'library_installed' => true,
			'library_active'    => is_plugin_active( self::LIB_FILE ),
			'elements'          => $out,
		);
	}

	public function get( $tag ) {
		$file = $this->elements_dir() . strtolower( trim( (string) $tag ) ) . '.json';
		if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', strtolower( trim( (string) $tag ) ) ) || ! is_file( $file ) ) {
			throw new InvalidArgumentException( 'Unknown custom element: ' . $tag );
		}
		return json_decode( (string) file_get_contents( $file ), true );
	}

	public function delete( $tag ) {
		$tag  = strtolower( trim( (string) $tag ) );
		$file = $this->elements_dir() . $tag . '.json';
		if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', $tag ) || ! is_file( $file ) ) {
			throw new InvalidArgumentException( 'Unknown custom element: ' . $tag );
		}
		global $wpdb;
		$in_use = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_status IN ('publish','draft','private')
				   AND post_type NOT IN ('revision')
				   AND post_content LIKE %s",
				'%[' . $wpdb->esc_like( $tag ) . '%'
			)
		);
		if ( $in_use > 0 ) {
			throw new RuntimeException( 'Element "' . $tag . '" appears on ' . $in_use . ' post(s) — remove it from content first (wpbakery_find_in_pages "[' . $tag . '"), or its shortcodes will render as literal text.' );
		}
		unlink( $file );
		return array(
			'tag'     => $tag,
			'deleted' => true,
		);
	}

	private function has_param( $params, $name ) {
		foreach ( $params as $p ) {
			if ( isset( $p['param_name'] ) && $name === $p['param_name'] ) {
				return true;
			}
		}
		return false;
	}
}
