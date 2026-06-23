<?php
/**
 * MCP WPBakery — admin status + connect page.
 *
 * The bridge is headless (REST + WP-CLI). This page (a) confirms the plugin is
 * active and WPBakery is detected, and (b) lets the user generate an Application
 * Password in one click and copy a complete, paste-ready prompt that gives an AI
 * agent everything it needs to connect (site URL, username, password, slug,
 * endpoint).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCP_WPBakery_Admin {

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
	}

	public function menu() {
		add_menu_page(
			'MCP WPBakery Bridge',
			'MCP WPBakery',
			'edit_posts',
			'mcp-wpbakery',
			array( $this, 'render' ),
			'dashicons-screenoptions',
			81
		);
	}

	/** A sensible default client slug from the site domain. */
	private function default_slug() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = preg_replace( '/^www\./', '', (string) $host );
		$first = explode( '.', $host )[0];
		return sanitize_key( $first ) ?: 'site';
	}

	private function handle_generate() {
		if ( empty( $_POST['mcp_generate'] ) || ! check_admin_referer( 'mcp_wpb_gen' ) ) {
			return null;
		}
		$user = wp_get_current_user();
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'error' => 'You need administrator access to generate an Application Password.' );
		}
		if ( function_exists( 'wp_is_application_passwords_available_for_user' )
			&& ! wp_is_application_passwords_available_for_user( $user ) ) {
			return array( 'error' => 'Application Passwords are not available for your account. They require HTTPS and must be enabled for this site/user.' );
		}
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return array( 'error' => 'This WordPress version does not support Application Passwords (needs 5.6+).' );
		}

		$slug = sanitize_key( wp_unslash( $_POST['mcp_slug'] ?? '' ) );
		if ( '' === $slug ) {
			$slug = $this->default_slug();
		}
		$created = WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array( 'name' => 'MCP WPBakery (' . gmdate( 'Y-m-d H:i' ) . ' UTC)' )
		);
		if ( is_wp_error( $created ) ) {
			return array( 'error' => $created->get_error_message() );
		}
		return array(
			'slug' => $slug,
			'user' => $user->user_login,
			'pw'   => WP_Application_Passwords::chunk_password( $created[0] ),
		);
	}

	public function render() {
		$core      = MCP_WPBakery_Core::instance();
		$vc_active = $core->is_vc_active();
		$vc_ver    = $core->vc_version();
		$rest_base = rest_url( MCP_WPBakery_REST::NS );
		$base_url  = untrailingslashit( home_url() );
		$slug      = $this->default_slug();

		$gen = $this->handle_generate();

		$el_count = null;
		$el_error = '';
		if ( $vc_active ) {
			try {
				$el_count = count( $core->get_elements() );
			} catch ( Throwable $e ) {
				$el_error = $e->getMessage();
			}
		}
		?>
		<div class="wrap">
			<h1>MCP WPBakery Bridge</h1>
			<p>Headless bridge that lets an AI agent (Claude) read this site's
			WPBakery elements and build native, editable ones. There is no editor
			here &mdash; it is driven over REST and WP-CLI.</p>

			<h2>Status</h2>
			<table class="widefat striped" style="max-width:780px">
				<tbody>
					<tr><td style="width:240px"><strong>Plugin</strong></td>
						<td>Active &mdash; v<?php echo esc_html( MCP_WPBAKERY_VERSION ); ?></td></tr>
					<tr><td><strong>WPBakery (js_composer)</strong></td>
						<td><?php echo $vc_active
							? '<span style="color:#137333">&#10003; Detected' . ( $vc_ver ? ' &mdash; v' . esc_html( $vc_ver ) : '' ) . '</span>'
							: '<span style="color:#b32d2e">&#10007; Not active</span>'; ?></td></tr>
					<tr><td><strong>Elements visible (vc_map)</strong></td>
						<td><?php
							if ( null !== $el_count ) {
								echo esc_html( $el_count ) . ' <span style="color:#646970">in this admin context; REST/WP-CLI (front-end) typically resolves more.</span>';
							} elseif ( $el_error ) {
								echo '<span style="color:#b32d2e">' . esc_html( $el_error ) . '</span>';
							} else { echo '&mdash;'; }
						?></td></tr>
					<tr><td><strong>REST endpoint</strong></td>
						<td><code><?php echo esc_html( $rest_base ); ?></code></td></tr>
				</tbody>
			</table>

			<h2>Connect an AI agent</h2>

			<?php if ( $gen && empty( $gen['error'] ) ) :
				$cfg_json = wp_json_encode(
					array(
						'base_url'             => $base_url,
						'wp_transport'         => 'rest',
						'wp_rest_user'         => $gen['user'],
						'wp_rest_app_password' => $gen['pw'],
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				);
				$slug   = $gen['slug'];
				$prompt = "Connect the \"wpbakery\" MCP to this WordPress site so you can build and edit WPBakery pages.\n\n"
					. "HOW IT WORKS: the wpbakery MCP server runs on THIS computer and talks to the site over the WordPress REST API, authenticating with the Application Password below. The plugin is already installed on the site.\n\n"
					. "STEP 1 - If the wpbakery_* tools are NOT available to you in this session, the MCP server isn't set up on this computer yet. Set it up, then RESTART Claude Code and paste this message again:\n"
					. "  git clone https://github.com/dvos301/mcp-wpbakery\n"
					. "  cd mcp-wpbakery && ./install.sh\n\n"
					. "STEP 2 - Once the wpbakery_* tools ARE available: create the file clients/{$slug}.json inside the mcp-wpbakery repo with exactly this content:\n\n"
					. $cfg_json . "\n\n"
					. "Then run wpbakery_ping with client \"{$slug}\" and tell me how many WPBakery elements it can see.";
				?>
				<div class="notice notice-success" style="padding:12px 14px;max-width:820px">
					<p style="margin-top:0"><strong>&#10003; Application Password created</strong>
					(named &ldquo;MCP WPBakery&rdquo; &mdash; revoke anytime under your profile).
					Copy the prompt below and paste it to your AI agent. It contains the password
					and is shown <strong>once</strong>.</p>
					<p style="margin:0 0 4px"><strong>First time on this computer?</strong> The agent needs the
					MCP server installed locally (<code>git clone</code> + <code>./install.sh</code>, then restart
					Claude Code). The prompt below walks you/the agent through that automatically if it's missing.</p>

					<p><strong>Paste this to your AI agent:</strong>
						<button type="button" class="button button-primary mcp-copy" data-target="mcp-prompt">Copy prompt</button></p>
					<textarea id="mcp-prompt" readonly rows="11"
						style="width:100%;font-family:monospace;font-size:12px"><?php echo esc_textarea( $prompt ); ?></textarea>

					<p style="margin-bottom:6px"><strong>Or just the config</strong> (<code>clients/<?php echo esc_html( $gen['slug'] ); ?>.json</code>):
						<button type="button" class="button mcp-copy" data-target="mcp-cfg">Copy JSON</button></p>
					<textarea id="mcp-cfg" readonly rows="6"
						style="width:100%;font-family:monospace;font-size:12px"><?php echo esc_textarea( $cfg_json ); ?></textarea>
				</div>
				<script>
				document.querySelectorAll('.mcp-copy').forEach(function(btn){
					btn.addEventListener('click',function(){
						var t=document.getElementById(btn.dataset.target);
						t.select(); t.setSelectionRange(0,99999);
						navigator.clipboard.writeText(t.value);
						var o=btn.textContent; btn.textContent='Copied!';
						setTimeout(function(){btn.textContent=o;},1500);
					});
				});
				</script>
			<?php else : ?>
				<?php if ( $gen && ! empty( $gen['error'] ) ) : ?>
					<div class="notice notice-error"><p><?php echo esc_html( $gen['error'] ); ?></p></div>
				<?php endif; ?>
				<p>Generate an Application Password and a ready-to-paste setup prompt for your AI agent.
				It bundles the site URL, your username, the password, the client slug, and the endpoint
				&mdash; so the agent has full context.</p>
				<form method="post">
					<?php wp_nonce_field( 'mcp_wpb_gen' ); ?>
					<table class="form-table" style="max-width:620px"><tbody>
						<tr>
							<th scope="row"><label for="mcp_slug">Client slug</label></th>
							<td><input type="text" id="mcp_slug" name="mcp_slug" value="<?php echo esc_attr( $slug ); ?>" class="regular-text">
								<p class="description">Short label the agent uses for this site (e.g. <code><?php echo esc_html( $slug ); ?></code>).</p></td>
						</tr>
					</tbody></table>
					<?php if ( ! current_user_can( 'manage_options' ) ) : ?>
						<p><em>Ask an administrator to generate the Application Password.</em></p>
					<?php else : ?>
						<p><button type="submit" name="mcp_generate" value="1" class="button button-primary">Generate Application Password &amp; prompt</button></p>
					<?php endif; ?>
				</form>
			<?php endif; ?>

			<p style="color:#646970;max-width:820px">The agent also needs the MCP server installed on its machine
			(<code>git clone</code> the repo &rarr; <code>./install.sh</code>). See the repo's ONBOARDING guide.</p>
		</div>
		<?php
	}
}
