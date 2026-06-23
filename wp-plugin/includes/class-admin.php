<?php
/**
 * MCP WPBakery — admin status page.
 *
 * The bridge is headless (REST + WP-CLI), so this page exists only to give a
 * visual confirmation that the plugin is active, that WPBakery is detected,
 * how many vc_map elements it can see, and the REST base URL + setup steps.
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

	public function render() {
		$core      = MCP_WPBakery_Core::instance();
		$vc_active = $core->is_vc_active();
		$vc_ver    = $core->vc_version();
		$rest_base = rest_url( MCP_WPBakery_REST::NS );

		$el_count = null;
		$el_error = '';
		if ( $vc_active ) {
			try {
				$el_count = count( $core->get_elements() );
			} catch ( Throwable $e ) {
				$el_error = $e->getMessage();
			}
		}

		$pw_url = admin_url( 'profile.php#application-passwords-section' );
		?>
		<div class="wrap">
			<h1>MCP WPBakery Bridge</h1>
			<p>Headless bridge that lets an AI agent (Claude) read this site's
			WPBakery element registry and build native, editable elements.
			There is no editor here &mdash; it is driven over REST and WP-CLI.</p>

			<h2>Status</h2>
			<table class="widefat striped" style="max-width:760px">
				<tbody>
					<tr>
						<td style="width:240px"><strong>Plugin</strong></td>
						<td>Active &mdash; v<?php echo esc_html( MCP_WPBAKERY_VERSION ); ?></td>
					</tr>
					<tr>
						<td><strong>WPBakery (js_composer)</strong></td>
						<td>
							<?php if ( $vc_active ) : ?>
								<span style="color:#137333">&#10003; Detected<?php echo $vc_ver ? ' &mdash; v' . esc_html( $vc_ver ) : ''; ?></span>
							<?php else : ?>
								<span style="color:#b32d2e">&#10007; Not active &mdash; activate WPBakery Page Builder.</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong>Elements visible (vc_map)</strong></td>
						<td>
							<?php
							if ( null !== $el_count ) {
								echo esc_html( $el_count );
								echo ' <span style="color:#646970">in this admin context. The agent connects over REST/WP-CLI (front-end context), which typically resolves more.</span>';
							} elseif ( $el_error ) {
								echo '<span style="color:#b32d2e">' . esc_html( $el_error ) . '</span>';
							} else {
								echo '&mdash;';
							}
							?>
						</td>
					</tr>
					<tr>
						<td><strong>REST base URL</strong></td>
						<td><code><?php echo esc_html( $rest_base ); ?></code></td>
					</tr>
					<tr>
						<td><strong>Your account can edit pages</strong></td>
						<td><?php echo current_user_can( 'edit_posts' ) ? '&#10003; yes' : '&#10007; no'; ?></td>
					</tr>
				</tbody>
			</table>

			<h2>Connect the MCP server (REST)</h2>
			<ol>
				<li>Create an Application Password:
					<a href="<?php echo esc_url( $pw_url ); ?>">Users &rarr; Profile &rarr; Application Passwords</a>.</li>
				<li>Give the username + generated password to the MCP server, e.g.:
					<pre style="background:#f6f7f7;padding:10px;max-width:760px;overflow:auto">export WPBAKERY_<?php echo esc_html( strtoupper( 'vista' ) ); ?>_USER="<?php echo esc_html( wp_get_current_user()->user_login ); ?>"
export WPBAKERY_<?php echo esc_html( strtoupper( 'vista' ) ); ?>_APP_PW="xxxx xxxx xxxx xxxx xxxx xxxx"</pre>
					(Replace <code>VISTA</code> with the client slug if different.)</li>
				<li>The agent then calls <code>wpbakery_ping</code> against the
					<code><?php echo esc_html( $rest_base ); ?></code> endpoint.</li>
			</ol>

			<?php if ( $vc_active && null !== $el_count ) : ?>
				<p style="color:#137333"><strong>&#10003; Ready.</strong> The bridge can see
				<?php echo esc_html( $el_count ); ?> WPBakery elements and is reachable over REST.</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
