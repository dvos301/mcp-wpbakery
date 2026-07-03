<?php
/**
 * MCP WPBakery — tool registry for the remote MCP endpoint.
 *
 * Mirrors the local hub's wpbakery_* tools (same names, minus the client
 * param — the connection itself identifies the site) and adds site-wide
 * capabilities: search, internal links, diagnostics, SEO meta, cloning,
 * and a full REST proxy.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCP_WPBakery_MCP_Tools {

	/** @var array<string,array> */
	private $tools = array();

	/** @var MCP_WPBakery_Core */
	private $core;

	/** @var MCP_WPBakery_Site_Tools */
	private $site;

	public function __construct() {
		$this->core = MCP_WPBakery_Core::instance();
		$this->site = new MCP_WPBakery_Site_Tools();
		$this->register_all();
	}

	public function capability_for( $name ) {
		return isset( $this->tools[ $name ] ) ? $this->tools[ $name ]['capability'] : null;
	}

	public function list_for_mcp() {
		$out = array();
		foreach ( $this->tools as $name => $def ) {
			$out[] = array(
				'name'        => $name,
				'description' => $def['description'],
				'inputSchema' => $def['schema'],
			);
		}
		return $out;
	}

	public function call( $name, $args ) {
		if ( ! isset( $this->tools[ $name ] ) ) {
			throw new InvalidArgumentException( 'Unknown tool: ' . $name );
		}
		return call_user_func( $this->tools[ $name ]['handler'], is_array( $args ) ? $args : array() );
	}

	private function add( $name, $description, $schema, $handler, $capability = 'edit_posts' ) {
		$this->tools[ $name ] = array(
			'description' => $description,
			'capability'  => $capability,
			'schema'      => $schema,
			'handler'     => $handler,
		);
	}

	private function obj( $properties = array(), $required = array() ) {
		$schema = array(
			'type'       => 'object',
			'properties' => empty( $properties ) ? new stdClass() : $properties,
		);
		if ( ! empty( $required ) ) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	private function register_all() {
		$core = $this->core;
		$site = $this->site;

		/* ---- WPBakery builder tools (parity with the local hub) ---- */

		$this->add(
			'wpbakery_ping',
			'Check the bridge: plugin/WP/WPBakery versions and site URL. Call first.',
			$this->obj(),
			function ( $args ) use ( $core ) {
				return array(
					'plugin'     => MCP_WPBAKERY_VERSION,
					'wp'         => get_bloginfo( 'version' ),
					'vc_active'  => $core->is_vc_active(),
					'vc_version' => $core->vc_version(),
					'site_url'   => home_url(),
				);
			}
		);
		$this->add(
			'wpbakery_list_elements',
			'List every WPBakery element registered on the site (the live vc_map): core, theme and addon elements. Summaries by default; full=true for complete schemas.',
			$this->obj( array( 'full' => array( 'type' => 'boolean', 'default' => false ) ) ),
			function ( $args ) use ( $core ) {
				return array( 'elements' => $core->get_elements( empty( $args['full'] ) ) );
			}
		);
		$this->add(
			'wpbakery_element_schema',
			'Full parameter schema for ONE WPBakery element: param names, types, defaults, dropdown options, dependencies.',
			$this->obj( array( 'tag' => array( 'type' => 'string' ) ), array( 'tag' ) ),
			function ( $args ) use ( $core ) {
				return $core->get_element( isset( $args['tag'] ) ? (string) $args['tag'] : '' );
			}
		);
		$this->add(
			'wpbakery_list_pages',
			'List pages/posts, flagging which were built with WPBakery.',
			$this->obj(
				array(
					'post_type' => array( 'type' => 'string', 'default' => 'page' ),
					'search'    => array( 'type' => 'string' ),
					'limit'     => array( 'type' => 'integer', 'default' => 100 ),
				)
			),
			function ( $args ) use ( $core ) {
				$q = array();
				if ( ! empty( $args['post_type'] ) ) {
					$q['post_type'] = explode( ',', (string) $args['post_type'] );
				}
				if ( ! empty( $args['search'] ) ) {
					$q['s'] = (string) $args['search'];
				}
				if ( ! empty( $args['limit'] ) ) {
					$q['posts_per_page'] = (int) $args['limit'];
				}
				return array( 'posts' => $core->list_posts( $q ) );
			}
		);
		$this->add(
			'wpbakery_get_page',
			'Get a page\'s full WPBakery state: raw shortcode content, parsed structure tree, and its generated CSS.',
			$this->obj( array( 'post_id' => array( 'type' => 'integer' ) ), array( 'post_id' ) ),
			function ( $args ) use ( $core ) {
				return $core->get_post_data( isset( $args['post_id'] ) ? (int) $args['post_id'] : 0 );
			}
		);
		$this->add(
			'wpbakery_get_structure',
			'Get only the parsed shortcode structure tree for a page.',
			$this->obj( array( 'post_id' => array( 'type' => 'integer' ) ), array( 'post_id' ) ),
			function ( $args ) use ( $core ) {
				$data = $core->get_post_data( isset( $args['post_id'] ) ? (int) $args['post_id'] : 0 );
				return array( 'structure' => isset( $data['structure'] ) ? $data['structure'] : array() );
			}
		);
		$this->add(
			'wpbakery_build_element',
			'Build ONE spec-compliant WPBakery element shortcode (validated against vc_map). Returns the string + warnings; does NOT write.',
			$this->obj(
				array(
					'tag'   => array( 'type' => 'string' ),
					'atts'  => array( 'type' => 'object' ),
					'inner' => array( 'type' => 'string' ),
				),
				array( 'tag' )
			),
			function ( $args ) use ( $core ) {
				return $core->build_element(
					isset( $args['tag'] ) ? (string) $args['tag'] : '',
					isset( $args['atts'] ) && is_array( $args['atts'] ) ? $args['atts'] : array(),
					isset( $args['inner'] ) ? (string) $args['inner'] : ''
				);
			}
		);
		$this->add(
			'wpbakery_validate',
			'Validate a WPBakery shortcode string against the site\'s vc_map without saving. Run before wpbakery_update_page.',
			$this->obj( array( 'content' => array( 'type' => 'string' ) ), array( 'content' ) ),
			function ( $args ) use ( $core ) {
				return $core->validate( isset( $args['content'] ) ? (string) $args['content'] : '' );
			}
		);
		$this->add(
			'wpbakery_update_page',
			'Write new WPBakery shortcode content to a page: validates, saves a revision backup, writes, regenerates CSS, optionally sets page CSS.',
			$this->obj(
				array(
					'post_id'       => array( 'type' => 'integer' ),
					'content'       => array( 'type' => 'string' ),
					'skip_validate' => array( 'type' => 'boolean', 'default' => false ),
					'page_css'      => array( 'type' => 'string' ),
				),
				array( 'post_id', 'content' )
			),
			function ( $args ) use ( $core ) {
				return $core->update_post(
					isset( $args['post_id'] ) ? (int) $args['post_id'] : 0,
					isset( $args['content'] ) ? (string) $args['content'] : '',
					empty( $args['skip_validate'] ),
					array_key_exists( 'page_css', $args ) ? (string) $args['page_css'] : null
				);
			}
		);
		$this->add(
			'wpbakery_set_page_css',
			'Set a page\'s WPBakery custom CSS without touching content. Use to skin native elements via el_class.',
			$this->obj(
				array(
					'post_id' => array( 'type' => 'integer' ),
					'css'     => array( 'type' => 'string' ),
				),
				array( 'post_id', 'css' )
			),
			function ( $args ) use ( $core ) {
				return array( 'page_css' => $core->set_page_css( (int) $args['post_id'], (string) $args['css'] ) );
			}
		);
		$this->add(
			'wpbakery_append_page_css',
			'Append CSS rules to a page\'s custom CSS without resending the whole sheet. Cheap styling iteration.',
			$this->obj(
				array(
					'post_id' => array( 'type' => 'integer' ),
					'css'     => array( 'type' => 'string' ),
				),
				array( 'post_id', 'css' )
			),
			function ( $args ) use ( $core ) {
				return array( 'page_css' => $core->append_page_css( (int) $args['post_id'], (string) $args['css'] ) );
			}
		);
		$this->add(
			'wpbakery_render_preview',
			'Render a page through the real front-end (drafts included): tokenized preview_url, unrendered_shortcodes to fix, rendered excerpt. ALWAYS call after writing.',
			$this->obj( array( 'post_id' => array( 'type' => 'integer' ) ), array( 'post_id' ) ),
			function ( $args ) use ( $core ) {
				return $core->render_preview( isset( $args['post_id'] ) ? (int) $args['post_id'] : 0 );
			}
		);
		$this->add(
			'wpbakery_create_page',
			'Create a new page (draft by default); returns id, edit link, URL.',
			$this->obj(
				array(
					'title'  => array( 'type' => 'string' ),
					'slug'   => array( 'type' => 'string' ),
					'status' => array( 'type' => 'string', 'default' => 'draft' ),
				),
				array( 'title' )
			),
			function ( $args ) use ( $core ) {
				return $core->create_page(
					isset( $args['title'] ) ? (string) $args['title'] : '',
					isset( $args['slug'] ) ? (string) $args['slug'] : '',
					isset( $args['status'] ) ? (string) $args['status'] : 'draft'
				);
			}
		);
		$this->add(
			'wpbakery_set_status',
			'Change a page\'s status (publish | draft | pending | private | future | trash). Busts caches.',
			$this->obj(
				array(
					'post_id' => array( 'type' => 'integer' ),
					'status'  => array( 'type' => 'string' ),
				),
				array( 'post_id', 'status' )
			),
			function ( $args ) use ( $core ) {
				return $core->set_status( (int) $args['post_id'], (string) $args['status'] );
			}
		);
		$this->add(
			'wpbakery_set_post_meta',
			'Set a post meta value (e.g. rank_math_title, rank_math_robots). For arrays pass a JSON string with is_json=true.',
			$this->obj(
				array(
					'post_id' => array( 'type' => 'integer' ),
					'key'     => array( 'type' => 'string' ),
					'value'   => array( 'type' => 'string' ),
					'is_json' => array( 'type' => 'boolean', 'default' => false ),
				),
				array( 'post_id', 'key', 'value' )
			),
			function ( $args ) use ( $core ) {
				return $core->set_post_meta(
					(int) $args['post_id'],
					(string) $args['key'],
					(string) $args['value'],
					! empty( $args['is_json'] )
				);
			}
		);
		$this->add(
			'wpbakery_replace_in_content',
			'Surgically replace a substring in a page\'s shortcode content. Set expected to require an exact match count (safety).',
			$this->obj(
				array(
					'post_id'  => array( 'type' => 'integer' ),
					'find'     => array( 'type' => 'string' ),
					'replace'  => array( 'type' => 'string' ),
					'expected' => array( 'type' => 'integer' ),
				),
				array( 'post_id', 'find', 'replace' )
			),
			function ( $args ) use ( $core ) {
				return $core->replace_in_content(
					(int) $args['post_id'],
					(string) $args['find'],
					(string) $args['replace'],
					isset( $args['expected'] ) ? (int) $args['expected'] : null
				);
			}
		);
		$this->add(
			'wpbakery_purge_cache',
			'Bust object + page caches for a page. Writes already auto-purge; use if a change looks stale.',
			$this->obj( array( 'post_id' => array( 'type' => 'integer' ) ), array( 'post_id' ) ),
			function ( $args ) use ( $core ) {
				return array( 'caches_purged' => $core->purge_caches( (int) $args['post_id'] ) );
			}
		);

		/* ---- Site-wide capabilities ---- */

		$this->add(
			'wpbakery_find_pages',
			'Find pages/posts by title, slug or content — or resolve a URL straight to its post.',
			$this->obj(
				array(
					'search'    => array( 'type' => 'string' ),
					'url'       => array( 'type' => 'string' ),
					'post_type' => array( 'type' => 'string', 'default' => 'any' ),
					'status'    => array( 'type' => 'string', 'default' => 'any' ),
					'limit'     => array( 'type' => 'integer', 'default' => 50 ),
				)
			),
			array( $site, 'find_pages' )
		);
		$this->add(
			'wpbakery_find_in_pages',
			'Sitewide text search: which pages contain this text, down to the WPBakery shortcode element holding the match.',
			$this->obj(
				array(
					'text'  => array( 'type' => 'string' ),
					'limit' => array( 'type' => 'integer', 'default' => 20 ),
				),
				array( 'text' )
			),
			array( $site, 'find_in_pages' )
		);
		$this->add(
			'wpbakery_page_links',
			'All links on one page with anchor text, the element type each lives in, and resolved internal targets. Reads HTML anchors AND WPBakery link attributes.',
			$this->obj(
				array(
					'post_id'       => array( 'type' => 'integer' ),
					'internal_only' => array( 'type' => 'boolean', 'default' => false ),
				),
				array( 'post_id' )
			),
			array( $site, 'page_links' )
		);
		$this->add(
			'wpbakery_inbound_links',
			'Which pages link TO a given page/URL, with every anchor text and an anchor-frequency summary. Includes nav menus.',
			$this->obj(
				array(
					'post_id' => array( 'type' => 'integer' ),
					'url'     => array( 'type' => 'string' ),
				)
			),
			array( $site, 'inbound_links' )
		);
		$this->add(
			'wpbakery_link_map',
			'Site-wide internal link graph: every internal link with its anchor, most-linked pages, and orphan pages.',
			$this->obj(
				array(
					'include_menus' => array( 'type' => 'boolean', 'default' => true ),
					'max_posts'     => array( 'type' => 'integer', 'default' => 300 ),
				)
			),
			array( $site, 'link_map' )
		);
		$this->add(
			'wpbakery_site_status',
			'Site health snapshot: WP/PHP/theme/plugin versions, pending updates, debug flags, WPBakery status.',
			$this->obj(),
			array( $site, 'site_status' ),
			'manage_options'
		);
		$this->add(
			'wpbakery_read_error_log',
			'Tail the WordPress debug log (and PHP error log if readable) to pick up recent errors.',
			$this->obj( array( 'lines' => array( 'type' => 'integer', 'default' => 100 ) ) ),
			array( $site, 'read_error_log' ),
			'manage_options'
		);
		$this->add(
			'wpbakery_get_seo_meta',
			'Read a page\'s SEO title, meta description and focus keyword (auto-detects Rank Math or Yoast).',
			$this->obj( array( 'post_id' => array( 'type' => 'integer' ) ), array( 'post_id' ) ),
			array( $site, 'get_seo_meta' )
		);
		$this->add(
			'wpbakery_set_seo_meta',
			'Set a page\'s SEO title, meta description and/or focus keyword (auto-detects Rank Math or Yoast).',
			$this->obj(
				array(
					'post_id'       => array( 'type' => 'integer' ),
					'title'         => array( 'type' => 'string' ),
					'description'   => array( 'type' => 'string' ),
					'focus_keyword' => array( 'type' => 'string' ),
				),
				array( 'post_id' )
			),
			array( $site, 'set_seo_meta' )
		);
		$this->add(
			'wpbakery_clone_page',
			'Duplicate a page (content + all meta incl. page CSS) as a new draft — fastest way to build from an existing design.',
			$this->obj(
				array(
					'post_id'   => array( 'type' => 'integer' ),
					'new_title' => array( 'type' => 'string' ),
				),
				array( 'post_id' )
			),
			array( $site, 'clone_page' )
		);
		$this->add(
			'wpbakery_rest_request',
			'Call any WordPress REST API route in-process as the token\'s user — core and every plugin\'s routes. Use wpbakery_list_rest_routes to discover. Prefer purpose-built tools when one fits.',
			$this->obj(
				array(
					'route'  => array( 'type' => 'string' ),
					'method' => array(
						'type'    => 'string',
						'enum'    => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
						'default' => 'GET',
					),
					'body'   => array( 'type' => 'object' ),
				),
				array( 'route' )
			),
			array( $site, 'rest_request' ),
			'manage_options'
		);
		$this->add(
			'wpbakery_list_rest_routes',
			'List the REST API routes registered on this site (core and plugins), optionally filtered by substring.',
			$this->obj( array( 'search' => array( 'type' => 'string' ) ) ),
			array( $site, 'list_rest_routes' ),
			'manage_options'
		);

		/* ---- Element Studio: reusable custom elements that OUTLIVE this plugin ---- */

		$studio          = new MCP_WPBakery_Element_Studio();
		$studio_schema   = $this->obj(
			array(
				'tag'         => array( 'type' => 'string', 'description' => 'Shortcode base, lowercase with a site prefix, e.g. "pwd_feature_grid". Reserved prefixes vc_/us_/wp_ are refused.' ),
				'name'        => array( 'type' => 'string', 'description' => 'Display name in the WPBakery element picker.' ),
				'description' => array( 'type' => 'string' ),
				'category'    => array( 'type' => 'string', 'default' => 'Custom Elements' ),
				'params'      => array( 'type' => 'array', 'description' => 'vc_map params (type, param_name, heading, value, std, group, params for param_group). Extra key "escape": text|html|url controls output escaping for {{param}}.' ),
				'template'    => array( 'type' => 'string', 'description' => 'HTML with {{param}} (escaped), {{{param}}} (kses HTML), {{#if p}}...{{/if}}, {{#each group}}...{{/each}} for param_group rows, {{content}} for enclosing elements.' ),
				'css'         => array( 'type' => 'string', 'description' => 'Element stylesheet, printed once per page when used.' ),
			),
			array( 'tag', 'name', 'template' )
		);

		$this->add(
			'wpbakery_create_custom_element',
			'Create a reusable custom WPBakery element (native, editable via vc_map params). It is written into the standalone "Custom WPBakery Elements" library plugin, so it keeps working even if this bridge plugin is deleted. The library is auto-created and activated on first use.',
			$studio_schema,
			function ( $args ) use ( $studio ) {
				return $studio->save( $args, false );
			},
			'install_plugins'
		);
		$this->add(
			'wpbakery_update_custom_element',
			'Update an existing custom element definition (same fields as create; full replace).',
			$studio_schema,
			function ( $args ) use ( $studio ) {
				return $studio->save( $args, true );
			},
			'install_plugins'
		);
		$this->add(
			'wpbakery_list_custom_elements',
			'List the site\'s custom elements from the standalone library plugin.',
			$this->obj(),
			function ( $args ) use ( $studio ) {
				return $studio->all();
			}
		);
		$this->add(
			'wpbakery_get_custom_element',
			'Get one custom element\'s full definition (params, template, css) for review or as a base for an update.',
			$this->obj( array( 'tag' => array( 'type' => 'string' ) ), array( 'tag' ) ),
			function ( $args ) use ( $studio ) {
				return $studio->get( isset( $args['tag'] ) ? $args['tag'] : '' );
			}
		);
		$this->add(
			'wpbakery_delete_custom_element',
			'Delete a custom element definition. Refuses if the shortcode is still used in any post content.',
			$this->obj( array( 'tag' => array( 'type' => 'string' ) ), array( 'tag' ) ),
			function ( $args ) use ( $studio ) {
				return $studio->delete( isset( $args['tag'] ) ? $args['tag'] : '' );
			},
			'install_plugins'
		);
	}
}
