<?php
/**
 * MCP WPBakery — site-wide capabilities beyond the page builder:
 * page finding, sitewide text search, internal-link/anchor analysis,
 * diagnostics, SEO meta, page cloning, and a REST proxy.
 *
 * Transport-agnostic like MCP_WPBakery_Core; driven by the MCP tool registry.
 * Link extraction understands both HTML anchors and WPBakery's encoded link
 * attributes (url:...|title:...|target:...).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCP_WPBakery_Site_Tools {

	private function core() {
		return MCP_WPBakery_Core::instance();
	}

	/* ------------------------------------------------------------------ */
	/* Finding pages                                                       */
	/* ------------------------------------------------------------------ */

	public function find_pages( $args ) {
		if ( ! empty( $args['url'] ) ) {
			$post_id = url_to_postid( (string) $args['url'] );
			return array( 'pages' => $post_id ? array( $this->row( $post_id ) ) : array() );
		}

		$limit    = max( 1, min( (int) ( isset( $args['limit'] ) ? $args['limit'] : 50 ), 200 ) );
		$status   = isset( $args['status'] ) ? (string) $args['status'] : 'any';
		$statuses = 'any' === $status ? array( 'publish', 'draft', 'private' ) : array( $status );
		$base     = array(
			'post_type'      => isset( $args['post_type'] ) ? $args['post_type'] : 'any',
			'post_status'    => $statuses,
			'posts_per_page' => $limit,
			'fields'         => 'ids',
		);

		$search = isset( $args['search'] ) ? (string) $args['search'] : '';
		$query  = new WP_Query( array_merge( $base, array( 's' => $search ) ) );
		$ids    = $query->posts;
		if ( '' !== $search ) {
			// 's' does not match slugs; merge an explicit slug lookup.
			$by_slug = new WP_Query( array_merge( $base, array( 'name' => sanitize_title( $search ) ) ) );
			$ids     = array_values( array_unique( array_merge( $ids, $by_slug->posts ) ) );
		}

		$pages = array();
		foreach ( array_slice( $ids, 0, $limit ) as $id ) {
			$pages[] = $this->row( (int) $id );
		}
		return array( 'pages' => $pages );
	}

	public function find_in_pages( $args ) {
		global $wpdb;
		$text = isset( $args['text'] ) ? (string) $args['text'] : '';
		if ( '' === trim( $text ) ) {
			throw new InvalidArgumentException( 'find_in_pages requires non-empty "text".' );
		}
		$limit = max( 1, min( (int) ( isset( $args['limit'] ) ? $args['limit'] : 20 ), 100 ) );
		$like  = '%' . $wpdb->esc_like( $text ) . '%';

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status IN ('publish','draft','private')
				   AND post_type NOT IN ('revision','attachment','nav_menu_item','customize_changeset','oembed_cache','custom_css','wp_global_styles')
				   AND post_content LIKE %s
				 ORDER BY post_modified DESC
				 LIMIT %d",
				$like,
				$limit
			)
		);

		$results = array();
		foreach ( (array) $ids as $id ) {
			$id      = (int) $id;
			$content = (string) get_post_field( 'post_content', $id );
			$matches = array( array(
				'location' => 'post_content',
				'snippet'  => $this->snippet( $content, $text ),
			) );
			// Pinpoint which shortcode element(s) contain the text.
			try {
				$tags = array();
				$this->find_in_nodes( $this->core()->parse( $content ), $text, $tags );
				foreach ( array_slice( $tags, 0, 10 ) as $hit ) {
					$matches[] = $hit;
				}
			} catch ( Throwable $e ) { // phpcs:ignore
				// A page that fails to parse must not sink the search.
			}
			$results[] = array_merge( $this->row( $id ), array( 'matches' => $matches ) );
		}
		return array(
			'text'    => $text,
			'results' => $results,
		);
	}

	private function find_in_nodes( $nodes, $needle, &$hits ) {
		foreach ( (array) $nodes as $node ) {
			if ( ! is_array( $node ) || empty( $node['tag'] ) ) {
				continue;
			}
			$own = '';
			if ( ! empty( $node['content'] ) && is_string( $node['content'] ) && false !== stripos( $node['content'], $needle ) ) {
				$own = $node['content'];
			} else {
				foreach ( (array) ( isset( $node['atts'] ) ? $node['atts'] : array() ) as $v ) {
					if ( is_string( $v ) && ( false !== stripos( $v, $needle ) || false !== stripos( rawurldecode( $v ), $needle ) ) ) {
						$own = rawurldecode( $v );
						break;
					}
				}
			}
			if ( '' !== $own ) {
				$hits[] = array(
					'location' => 'shortcode',
					'element'  => $node['tag'],
					'snippet'  => $this->snippet( $own, $needle ),
				);
			}
			if ( ! empty( $node['children'] ) ) {
				$this->find_in_nodes( $node['children'], $needle, $hits );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Internal links & anchors                                            */
	/* ------------------------------------------------------------------ */

	public function page_links( $args ) {
		$post_id = $this->assert_post( $args );
		$links   = array();
		foreach ( $this->extract( $post_id ) as $l ) {
			$l = array_merge( $l, $this->resolve( $l['href'] ) );
			if ( ! empty( $args['internal_only'] ) && ! $l['internal'] ) {
				continue;
			}
			$links[] = $l;
		}
		return array(
			'post_id' => $post_id,
			'count'   => count( $links ),
			'links'   => $links,
		);
	}

	public function inbound_links( $args ) {
		$target_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$url       = isset( $args['url'] ) ? (string) $args['url'] : '';
		if ( ! $target_id && '' === $url ) {
			throw new InvalidArgumentException( 'inbound_links needs a post_id or a url.' );
		}
		if ( ! $target_id && '' !== $url ) {
			$target_id = url_to_postid( $url );
		}
		$target_url = $target_id ? get_permalink( $target_id ) : $url;
		if ( ! $target_url ) {
			throw new InvalidArgumentException( 'Could not resolve the target.' );
		}

		$sources = array();
		foreach ( $this->candidate_sources( $target_url ) as $src_id ) {
			$src_id = (int) $src_id;
			if ( $src_id === $target_id ) {
				continue;
			}
			foreach ( $this->extract( $src_id ) as $l ) {
				if ( ! $this->hits_target( $l['href'], $target_id, $target_url ) ) {
					continue;
				}
				$sources[] = array(
					'post_id' => $src_id,
					'title'   => get_the_title( $src_id ),
					'url'     => get_permalink( $src_id ),
					'element' => isset( $l['element'] ) ? $l['element'] : null,
					'anchor'  => $l['anchor'],
					'href'    => $l['href'],
					'source'  => $l['source'],
				);
			}
		}
		foreach ( $this->menu_links() as $l ) {
			if ( $this->hits_target( $l['href'], $target_id, $target_url ) ) {
				$sources[] = array(
					'post_id' => null,
					'title'   => null,
					'url'     => null,
					'element' => null,
					'anchor'  => $l['anchor'],
					'href'    => $l['href'],
					'source'  => $l['source'],
				);
			}
		}

		$anchor_counts = array();
		foreach ( $sources as $s ) {
			$a = '' !== $s['anchor'] ? $s['anchor'] : '(no text / image link)';
			$anchor_counts[ $a ] = ( isset( $anchor_counts[ $a ] ) ? $anchor_counts[ $a ] : 0 ) + 1;
		}
		arsort( $anchor_counts );

		return array(
			'target_post_id' => $target_id ? $target_id : null,
			'target_url'     => $target_url,
			'count'          => count( $sources ),
			'anchor_counts'  => $anchor_counts,
			'sources'        => $sources,
		);
	}

	public function link_map( $args ) {
		$include_menus = ! isset( $args['include_menus'] ) || $args['include_menus'];
		$max_posts     = max( 1, min( (int) ( isset( $args['max_posts'] ) ? $args['max_posts'] : 300 ), 500 ) );

		$query = new WP_Query(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => $max_posts,
				'fields'         => 'ids',
			)
		);

		$edges   = array();
		$inbound = array();
		foreach ( $query->posts as $from ) {
			foreach ( $this->extract( (int) $from ) as $l ) {
				$r = $this->resolve( $l['href'] );
				if ( ! $r['internal'] ) {
					continue;
				}
				$to = $r['target_post_id'];
				if ( $to && $to !== (int) $from ) {
					$inbound[ $to ] = ( isset( $inbound[ $to ] ) ? $inbound[ $to ] : 0 ) + 1;
				}
				$edges[] = array(
					'from_post_id' => (int) $from,
					'to_post_id'   => $to,
					'href'         => $l['href'],
					'anchor'       => $l['anchor'],
					'element'      => isset( $l['element'] ) ? $l['element'] : null,
				);
			}
		}
		if ( $include_menus ) {
			foreach ( $this->menu_links() as $l ) {
				$r = $this->resolve( $l['href'] );
				if ( ! $r['internal'] ) {
					continue;
				}
				if ( $r['target_post_id'] ) {
					$inbound[ $r['target_post_id'] ] = ( isset( $inbound[ $r['target_post_id'] ] ) ? $inbound[ $r['target_post_id'] ] : 0 ) + 1;
				}
				$edges[] = array(
					'from_post_id' => null,
					'to_post_id'   => $r['target_post_id'],
					'href'         => $l['href'],
					'anchor'       => $l['anchor'],
					'element'      => null,
					'source'       => $l['source'],
				);
			}
		}

		arsort( $inbound );
		$top = array();
		foreach ( array_slice( $inbound, 0, 25, true ) as $pid => $n ) {
			$top[] = array(
				'post_id'       => $pid,
				'title'         => get_the_title( $pid ),
				'inbound_links' => $n,
			);
		}

		$front_page = (int) get_option( 'page_on_front' );
		$orphans    = array();
		foreach ( $query->posts as $pid ) {
			$pid = (int) $pid;
			if ( 'page' !== get_post_type( $pid ) || $pid === $front_page || ! empty( $inbound[ $pid ] ) ) {
				continue;
			}
			$orphans[] = array(
				'post_id' => $pid,
				'title'   => get_the_title( $pid ),
				'url'     => get_permalink( $pid ),
			);
		}

		return array(
			'posts_scanned'   => count( $query->posts ),
			'internal_links'  => count( $edges ),
			'top_targets'     => $top,
			'orphan_pages'    => $orphans,
			'edges'           => array_slice( $edges, 0, 2000 ),
			'edges_truncated' => count( $edges ) > 2000,
		);
	}

	/** @return array[] {href, anchor, source, element?} */
	private function extract( $post_id ) {
		$out     = array();
		$content = (string) get_post_field( 'post_content', $post_id );
		if ( '' === $content ) {
			return $out;
		}
		// HTML anchors anywhere (inside or outside shortcode bodies).
		foreach ( $this->html_anchors( $content ) as $l ) {
			$l['source'] = 'content';
			$out[]       = $l;
		}
		// WPBakery encoded link attributes (url:...|title:...) on elements.
		try {
			$this->walk_nodes_for_links( $this->core()->parse( $content ), $out );
		} catch ( Throwable $e ) { // phpcs:ignore
			// Unparseable content must not sink a scan.
		}
		return $out;
	}

	private function walk_nodes_for_links( $nodes, &$out ) {
		foreach ( (array) $nodes as $node ) {
			if ( ! is_array( $node ) || empty( $node['tag'] ) ) {
				continue;
			}
			$atts = isset( $node['atts'] ) && is_array( $node['atts'] ) ? $node['atts'] : array();
			$hint = '';
			foreach ( array( 'title', 'text', 'label', 'btn_title' ) as $k ) {
				if ( ! empty( $atts[ $k ] ) && is_string( $atts[ $k ] ) ) {
					$hint = trim( wp_strip_all_tags( $atts[ $k ] ) );
					break;
				}
			}
			foreach ( $atts as $v ) {
				if ( ! is_string( $v ) || '' === $v || ! preg_match( '#(^|\|)url:#', $v ) ) {
					continue;
				}
				$link = $this->parse_vc_link( $v );
				if ( '' !== $link['url'] ) {
					$out[] = array(
						'href'    => $link['url'],
						'anchor'  => '' !== $link['title'] ? $link['title'] : $hint,
						'element' => $node['tag'],
						'source'  => 'shortcode',
					);
				}
			}
			if ( ! empty( $node['children'] ) ) {
				$this->walk_nodes_for_links( $node['children'], $out );
			}
		}
	}

	/** Parse WPBakery's vc_build_link format: url:...|title:...|target:...|rel:... */
	private function parse_vc_link( $value ) {
		$link = array(
			'url'   => '',
			'title' => '',
		);
		foreach ( explode( '|', (string) $value ) as $piece ) {
			$pos = strpos( $piece, ':' );
			if ( false === $pos ) {
				continue;
			}
			$key = substr( $piece, 0, $pos );
			$val = rawurldecode( substr( $piece, $pos + 1 ) );
			if ( 'url' === $key || 'title' === $key ) {
				$link[ $key ] = trim( $val );
			}
		}
		return $link;
	}

	/** @return array[] {href, anchor} */
	private function html_anchors( $html ) {
		if ( ! preg_match_all( '#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', (string) $html, $m ) ) {
			return array();
		}
		$out = array();
		foreach ( $m[2] as $i => $href ) {
			$out[] = array(
				'href'   => html_entity_decode( $href ),
				'anchor' => trim( wp_strip_all_tags( $m[3][ $i ] ) ),
			);
		}
		return $out;
	}

	/** @return array {internal, target_post_id} */
	private function resolve( $href ) {
		$href = trim( (string) $href );
		if ( '' === $href || '#' === $href[0]
			|| 0 === stripos( $href, 'mailto:' ) || 0 === stripos( $href, 'tel:' )
			|| 0 === stripos( $href, 'javascript:' ) ) {
			return array(
				'internal'       => false,
				'target_post_id' => null,
			);
		}
		$home_host = $this->bare_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$host      = wp_parse_url( $href, PHP_URL_HOST );
		$full      = $href;
		if ( null === $host || '' === $host ) {
			$full = home_url( '/' === $href[0] ? $href : '/' . $href );
			$host = $home_host;
		}
		if ( 0 !== strcasecmp( $this->bare_host( (string) $host ), $home_host ) ) {
			return array(
				'internal'       => false,
				'target_post_id' => null,
			);
		}
		$clean = (string) strtok( $full, '#' );
		$pid   = url_to_postid( $clean );
		return array(
			'internal'       => true,
			'target_post_id' => $pid ? $pid : null,
		);
	}

	private function bare_host( $host ) {
		return preg_replace( '/^www\./i', '', strtolower( (string) $host ) );
	}

	private function hits_target( $href, $target_id, $target_url ) {
		$r = $this->resolve( $href );
		if ( ! $r['internal'] ) {
			return false;
		}
		if ( $target_id && $r['target_post_id'] === $target_id ) {
			return true;
		}
		$a = untrailingslashit( (string) wp_parse_url( $href, PHP_URL_PATH ) );
		$b = untrailingslashit( (string) wp_parse_url( $target_url, PHP_URL_PATH ) );
		return '' !== $b && 0 === strcasecmp( $a, $b )
			&& (string) wp_parse_url( $href, PHP_URL_QUERY ) === (string) wp_parse_url( $target_url, PHP_URL_QUERY );
	}

	/** Cheap pre-filter: only posts mentioning the target's path can link to it. */
	private function candidate_sources( $target_url ) {
		global $wpdb;
		$path   = (string) wp_parse_url( $target_url, PHP_URL_PATH );
		$query  = (string) wp_parse_url( $target_url, PHP_URL_QUERY );
		$needle = '' !== $query ? $query : ( strlen( untrailingslashit( $path ) ) > 1 ? untrailingslashit( $path ) : '' );

		if ( '' === $needle ) {
			$q = new WP_Query(
				array(
					'post_type'      => array( 'page', 'post' ),
					'post_status'    => 'publish',
					'posts_per_page' => 500,
					'fields'         => 'ids',
				)
			);
			return $q->posts;
		}

		return (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status = 'publish'
				   AND post_type NOT IN ('revision','attachment','nav_menu_item')
				   AND ( post_content LIKE %s OR post_content LIKE %s )
				 LIMIT 500",
				'%' . $wpdb->esc_like( $needle ) . '%',
				'%' . $wpdb->esc_like( rawurlencode( $needle ) ) . '%'
			)
		);
	}

	/** @return array[] {href, anchor, source} */
	private function menu_links() {
		$out = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu ) as $item ) {
				if ( empty( $item->url ) ) {
					continue;
				}
				$out[] = array(
					'href'   => (string) $item->url,
					'anchor' => (string) $item->title,
					'source' => 'menu:' . $menu->name,
				);
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Diagnostics                                                         */
	/* ------------------------------------------------------------------ */

	public function site_status( $args ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		global $wp_version;

		$updates = get_site_transient( 'update_plugins' );
		$plugins = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			$path = WP_PLUGIN_DIR . '/' . $file;
			$data = is_file( $path ) ? get_plugin_data( $path, false, false ) : array();
			$new  = false;
			if ( $updates && isset( $updates->response[ $file ] ) ) {
				$new = isset( $updates->response[ $file ]->new_version ) ? $updates->response[ $file ]->new_version : true;
			}
			$plugins[] = array(
				'plugin'           => $file,
				'name'             => isset( $data['Name'] ) ? $data['Name'] : $file,
				'version'          => isset( $data['Version'] ) ? $data['Version'] : '',
				'update_available' => $new,
			);
		}

		$theme = wp_get_theme();
		return array(
			'wp_version'          => $wp_version,
			'php_version'         => PHP_VERSION,
			'https'               => is_ssl(),
			'theme'               => array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'parent'  => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
			),
			'wpbakery'            => array(
				'active'  => $this->core()->is_vc_active(),
				'version' => $this->core()->vc_version(),
			),
			'active_plugins'      => $plugins,
			'debug'               => array(
				'WP_DEBUG'        => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'WP_DEBUG_LOG'    => defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false,
				'debug_log_found' => null !== $this->debug_log_path(),
			),
			'cache'               => array(
				'external_object_cache' => (bool) wp_using_ext_object_cache(),
			),
			'memory_limit'        => ini_get( 'memory_limit' ),
			'permalink_structure' => get_option( 'permalink_structure' ) ? get_option( 'permalink_structure' ) : '(plain)',
		);
	}

	public function read_error_log( $args ) {
		$lines = max( 1, min( (int) ( isset( $args['lines'] ) ? $args['lines'] : 100 ), 500 ) );
		$logs  = array();
		$paths = array_unique( array_filter( array( $this->debug_log_path(), (string) ini_get( 'error_log' ) ) ) );
		foreach ( $paths as $path ) {
			if ( ! @is_file( $path ) || ! @is_readable( $path ) ) { // phpcs:ignore
				continue;
			}
			$logs[] = array(
				'file'       => str_replace( ABSPATH, '', $path ),
				'size_bytes' => (int) filesize( $path ),
				'modified'   => gmdate( 'Y-m-d H:i:s', (int) filemtime( $path ) ) . ' UTC',
				'tail'       => $this->tail( $path, $lines ),
			);
		}
		if ( empty( $logs ) ) {
			return array(
				'logs' => array(),
				'note' => 'No readable log file found. Enable WP_DEBUG and WP_DEBUG_LOG in wp-config.php to capture PHP errors to wp-content/debug.log.',
			);
		}
		return array( 'logs' => $logs );
	}

	private function debug_log_path() {
		$path = ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) )
			? WP_DEBUG_LOG
			: WP_CONTENT_DIR . '/debug.log';
		return @is_file( $path ) ? $path : null; // phpcs:ignore
	}

	/** Read the last N lines without loading the whole file. */
	private function tail( $path, $lines ) {
		$fh = @fopen( $path, 'rb' ); // phpcs:ignore
		if ( ! $fh ) {
			return array();
		}
		fseek( $fh, 0, SEEK_END );
		$pos  = ftell( $fh );
		$data = '';
		while ( $pos > 0 && substr_count( $data, "\n" ) <= $lines && strlen( $data ) < 1048576 ) {
			$read = (int) min( 4096, $pos );
			$pos -= $read;
			fseek( $fh, $pos );
			$data = fread( $fh, $read ) . $data;
		}
		fclose( $fh );
		$all = explode( "\n", rtrim( $data, "\n" ) );
		return array_slice( $all, -$lines );
	}

	/* ------------------------------------------------------------------ */
	/* SEO meta                                                            */
	/* ------------------------------------------------------------------ */

	public function get_seo_meta( $args ) {
		$post_id = $this->assert_post( $args );
		$keys    = $this->seo_keys();
		if ( ! $keys ) {
			return array(
				'post_id' => $post_id,
				'plugin'  => null,
				'note'    => 'No supported SEO plugin (Rank Math or Yoast) is active.',
			);
		}
		return array(
			'post_id'       => $post_id,
			'plugin'        => $keys['plugin'],
			'title'         => (string) get_post_meta( $post_id, $keys['title'], true ),
			'description'   => (string) get_post_meta( $post_id, $keys['description'], true ),
			'focus_keyword' => (string) get_post_meta( $post_id, $keys['focus_keyword'], true ),
		);
	}

	public function set_seo_meta( $args ) {
		$post_id = $this->assert_post( $args );
		$keys    = $this->seo_keys();
		if ( ! $keys ) {
			throw new RuntimeException( 'No supported SEO plugin (Rank Math or Yoast) is active.' );
		}
		$updated = array();
		foreach ( array( 'title', 'description', 'focus_keyword' ) as $field ) {
			if ( isset( $args[ $field ] ) && is_string( $args[ $field ] ) ) {
				update_post_meta( $post_id, $keys[ $field ], wp_slash( sanitize_textarea_field( $args[ $field ] ) ) );
				$updated[] = $field;
			}
		}
		return array(
			'post_id' => $post_id,
			'plugin'  => $keys['plugin'],
			'updated' => $updated,
		);
	}

	private function seo_keys() {
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return array(
				'plugin'        => 'rank-math',
				'title'         => 'rank_math_title',
				'description'   => 'rank_math_description',
				'focus_keyword' => 'rank_math_focus_keyword',
			);
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return array(
				'plugin'        => 'yoast',
				'title'         => '_yoast_wpseo_title',
				'description'   => '_yoast_wpseo_metadesc',
				'focus_keyword' => '_yoast_wpseo_focuskw',
			);
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Clone                                                               */
	/* ------------------------------------------------------------------ */

	public function clone_page( $args ) {
		$src = get_post( isset( $args['post_id'] ) ? (int) $args['post_id'] : 0 );
		if ( ! $src ) {
			throw new InvalidArgumentException( 'Source post not found.' );
		}
		if ( ! current_user_can( 'edit_post', $src->ID ) ) {
			throw new RuntimeException( 'Not allowed to access post ' . $src->ID . '.' );
		}
		$new_id = wp_insert_post(
			array(
				'post_title'   => sanitize_text_field( isset( $args['new_title'] ) ? $args['new_title'] : ( $src->post_title . ' (Copy)' ) ),
				'post_type'    => $src->post_type,
				'post_status'  => 'draft',
				'post_content' => $src->post_content,
				'post_parent'  => $src->post_parent,
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			throw new RuntimeException( 'Failed to clone: ' . $new_id->get_error_message() );
		}
		$skip = array( '_edit_lock', '_edit_last', '_wp_old_slug' );
		foreach ( get_post_meta( $src->ID ) as $key => $values ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				add_post_meta( (int) $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
		$this->core()->regenerate_css( (int) $new_id );
		return array(
			'post_id'     => (int) $new_id,
			'title'       => get_the_title( $new_id ),
			'status'      => 'draft',
			'edit_url'    => get_edit_post_link( $new_id, 'raw' ),
			'preview_url' => get_permalink( $new_id ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* REST proxy                                                          */
	/* ------------------------------------------------------------------ */

	public function rest_request( $args ) {
		$route = isset( $args['route'] ) ? (string) $args['route'] : '';
		if ( '' === $route || '/' !== $route[0] ) {
			throw new InvalidArgumentException( 'route must start with "/", e.g. /wp/v2/pages' );
		}
		if ( 0 === strpos( $route, '/mcp-wpbakery/' ) ) {
			throw new InvalidArgumentException( 'Refusing to proxy to the mcp-wpbakery namespace itself.' );
		}
		$method = strtoupper( isset( $args['method'] ) ? (string) $args['method'] : 'GET' );
		if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			throw new InvalidArgumentException( 'Unsupported method: ' . $method );
		}

		$parts   = explode( '?', $route, 2 );
		$request = new WP_REST_Request( $method, $parts[0] );
		if ( isset( $parts[1] ) ) {
			parse_str( $parts[1], $query );
			foreach ( $query as $key => $value ) {
				$request->set_param( $key, $value );
			}
		}
		if ( ! empty( $args['body'] ) && is_array( $args['body'] ) ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $args['body'] ) );
		}

		// Dispatch through the real server so permission callbacks run as the
		// token's user — access is identical to app-password REST, not wider.
		$response = rest_do_request( $request );
		$data     = rest_get_server()->response_to_data( $response, false );

		return array(
			'status' => $response->get_status(),
			'data'   => $data,
		);
	}

	public function list_rest_routes( $args ) {
		$search = strtolower( isset( $args['search'] ) ? (string) $args['search'] : '' );
		$routes = array();
		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( '' !== $search && false === strpos( strtolower( $route ), $search ) ) {
				continue;
			}
			$methods = array();
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( (array) ( isset( $handler['methods'] ) ? $handler['methods'] : array() ) ) as $m ) {
					$methods[ $m ] = true;
				}
			}
			$routes[] = array(
				'route'   => $route,
				'methods' => array_keys( $methods ),
			);
		}
		return array(
			'count'  => count( $routes ),
			'routes' => $routes,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	private function assert_post( $args ) {
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		if ( ! get_post( $post_id ) ) {
			throw new InvalidArgumentException( 'Post ' . $post_id . ' not found.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new RuntimeException( 'Not allowed to access post ' . $post_id . '.' );
		}
		return $post_id;
	}

	private function row( $id ) {
		return array(
			'post_id'     => $id,
			'title'       => get_the_title( $id ),
			'slug'        => (string) get_post_field( 'post_name', $id ),
			'url'         => get_permalink( $id ),
			'status'      => get_post_status( $id ),
			'post_type'   => get_post_type( $id ),
			'is_wpbakery' => false !== strpos( (string) get_post_field( 'post_content', $id ), '[vc_' ),
			'modified'    => (string) get_post_field( 'post_modified_gmt', $id ) . ' UTC',
		);
	}

	private function snippet( $haystack, $needle ) {
		$plain = wp_strip_all_tags( (string) $haystack );
		$pos   = stripos( $plain, $needle );
		if ( false === $pos ) {
			return substr( $plain, 0, 120 );
		}
		$start = max( 0, $pos - 60 );
		$piece = substr( $plain, $start, 120 + strlen( $needle ) );
		return ( $start > 0 ? '…' : '' ) . trim( $piece ) . '…';
	}
}
