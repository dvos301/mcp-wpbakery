<?php
/**
 * MCP WPBakery — custom block elements.
 *
 * Optional, theme-proof design-system blocks (mcp_*) registered as NATIVE,
 * editable WPBakery elements via vc_map(). They sit in the element picker
 * ALONGSIDE the stock vc_* / theme us_* elements — nothing is removed; the
 * agent reaches for these only when a section needs controlled markup,
 * a repeated component, or guaranteed cross-theme consistency.
 *
 * Why they're theme-proof: each render callback returns FINAL HTML, so the
 * theme never re-parses it (no vc_icon-style leaks, no .g-cols quirks, no
 * page-CSS juggling). CSS ships in this plugin, printed once.
 *
 * Editable, NOT raw HTML: every field is a vc_map param (incl. repeatable
 * param_group rows), so the client edits each block in the builder panel.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCP_WPBakery_Blocks {

	/** Brand tokens — override per-site via the 'mcp_blocks_tokens' filter. */
	private function tokens() {
		return apply_filters(
			'mcp_blocks_tokens',
			array(
				'navy'      => '#0B2648',
				'ink'       => '#0B2648',
				'body'      => '#48576E',
				'muted'     => '#A9B8CE',
				'orange'    => '#F2731C',
				'orange_lt' => '#F48539',
				'orange_dk' => '#D75E0F',
				'green'     => '#37964F',
				'green_dk'  => '#2E7E42',
				'surface'   => '#F4F7FB',
				'line'      => '#E6EBF2',
				'maxw'      => '1200px',
			)
		);
	}

	/** Register shortcodes (always) + vc_map (when WPBakery initialises). */
	public function register() {
		add_shortcode( 'mcp_hero', array( $this, 'render_hero' ) );
		add_shortcode( 'mcp_cards', array( $this, 'render_cards' ) );
		add_shortcode( 'mcp_faq', array( $this, 'render_faq' ) );
		add_shortcode( 'mcp_cta', array( $this, 'render_cta' ) );
		add_action( 'vc_before_init', array( $this, 'map' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                               */
	/* --------------------------------------------------------------------- */

	/** Print the block stylesheet exactly once (first block on the page). */
	private function css_once() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		return "<style id='mcp-blocks-css'>" . $this->base_css() . '</style>';
	}

	/** Decode a param_group value into a list of assoc rows. */
	private function group( $value ) {
		if ( empty( $value ) ) {
			return array();
		}
		if ( function_exists( 'vc_param_group_parse_atts' ) ) {
			return (array) vc_param_group_parse_atts( $value );
		}
		$decoded = json_decode( rawurldecode( $value ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/** Render the button row from a param_group ([{label,url,style}]). */
	private function buttons( $rows ) {
		if ( ! $rows ) {
			return '';
		}
		$out = '';
		foreach ( $rows as $b ) {
			$style = isset( $b['style'] ) ? $b['style'] : 'primary';
			$url   = isset( $b['url'] ) && '' !== $b['url'] ? $b['url'] : '#';
			$out  .= '<a class="mcp-btn mcp-btn-' . esc_attr( $style ) . '" href="' . esc_url( $url ) . '">'
				. esc_html( isset( $b['label'] ) ? $b['label'] : '' ) . '</a>';
		}
		return $out;
	}

	private function eyebrow( $text, $variant = '' ) {
		if ( '' === $text ) {
			return '';
		}
		return '<p class="mcp-eyebrow ' . esc_attr( $variant ) . '"><span>' . esc_html( $text ) . '</span></p>';
	}

	/* --------------------------------------------------------------------- */
	/* Render callbacks (return final, theme-proof HTML)                      */
	/* --------------------------------------------------------------------- */

	public function render_hero( $atts ) {
		$a = shortcode_atts(
			array(
				'eyebrow'         => '',
				'heading'         => '',
				'heading_accent'  => '',
				'sub'             => '',
				'buttons'         => '',
				'pills'           => '',
				'card_title'      => '',
				'bars'            => '',
				'card_note'       => '',
			),
			$atts
		);
		$h  = $this->css_once();
		$h .= '<section class="mcp-blk mcp-hero"><div class="mcp-wrap"><div class="mcp-hero-grid">';
		$h .= '<div class="mcp-hero-copy">';
		$h .= $this->eyebrow( $a['eyebrow'] );
		if ( '' !== $a['heading'] ) {
			$h .= '<h1 class="mcp-h1">' . esc_html( $a['heading'] );
			if ( '' !== $a['heading_accent'] ) {
				$h .= '<em>' . esc_html( $a['heading_accent'] ) . '</em>';
			}
			$h .= '</h1>';
		}
		if ( '' !== $a['sub'] ) {
			$h .= '<p class="mcp-sub">' . esc_html( $a['sub'] ) . '</p>';
		}
		$btns = $this->buttons( $this->group( $a['buttons'] ) );
		if ( $btns ) {
			$h .= '<div class="mcp-actions">' . $btns . '</div>';
		}
		$pills = $this->group( $a['pills'] );
		if ( $pills ) {
			$h .= '<div class="mcp-trust">';
			foreach ( $pills as $p ) {
				$h .= '<span class="mcp-pill">' . wp_kses_post( isset( $p['text'] ) ? $p['text'] : '' ) . '</span>';
			}
			$h .= '</div>';
		}
		$h .= '</div>'; // copy

		$bars = $this->group( $a['bars'] );
		if ( '' !== $a['card_title'] || $bars ) {
			$h .= '<div class="mcp-hero-aside"><div class="mcp-scard">';
			if ( '' !== $a['card_title'] ) {
				$h .= '<div class="mcp-scard-t"><span class="mcp-scard-sun"></span>' . esc_html( $a['card_title'] ) . '</div>';
			}
			foreach ( $bars as $bar ) {
				$lit = ! empty( $bar['lit'] ) && 'true' === $bar['lit'];
				$pct = isset( $bar['pct'] ) ? (int) $bar['pct'] : 0;
				$h  .= '<div class="mcp-crow ' . ( $lit ? 'mcp-lit' : '' ) . '">';
				$h  .= '<div class="mcp-crow-top"><span class="mcp-crow-l">' . esc_html( isset( $bar['label'] ) ? $bar['label'] : '' )
					. '</span><span class="mcp-crow-p">' . esc_html( isset( $bar['note'] ) ? $bar['note'] : '' ) . '</span></div>';
				$h  .= '<div class="mcp-cbar"><span class="mcp-fill ' . ( $lit ? 'mcp-fill-lit' : 'mcp-fill-dim' )
					. '" style="width:' . $pct . '%">' . esc_html( isset( $bar['fill_label'] ) ? $bar['fill_label'] : '' ) . '</span></div>';
				$h  .= '</div>';
			}
			if ( '' !== $a['card_note'] ) {
				$h .= '<p class="mcp-scard-note">' . esc_html( $a['card_note'] ) . '</p>';
			}
			$h .= '</div></div>'; // aside
		}
		$h .= '</div></div></section>';
		return $h;
	}

	public function render_cards( $atts ) {
		$a = shortcode_atts(
			array(
				'eyebrow'      => '',
				'eyebrow_color'=> 'orange',
				'heading'      => '',
				'lede'         => '',
				'surface'      => 'yes',
				'cards'        => '',
			),
			$atts
		);
		$cards = $this->group( $a['cards'] );
		$h     = $this->css_once();
		$h    .= '<section class="mcp-blk mcp-section ' . ( 'yes' === $a['surface'] ? 'mcp-surface' : '' ) . '"><div class="mcp-wrap">';
		$h    .= $this->eyebrow( $a['eyebrow'], 'green' === $a['eyebrow_color'] ? 'green' : '' );
		if ( '' !== $a['heading'] ) {
			$h .= '<h2 class="mcp-h2">' . esc_html( $a['heading'] ) . '</h2>';
		}
		if ( '' !== $a['lede'] ) {
			$h .= '<p class="mcp-lede">' . esc_html( $a['lede'] ) . '</p>';
		}
		$h .= '<div class="mcp-grid3">';
		foreach ( $cards as $c ) {
			$icon = isset( $c['icon'] ) ? $c['icon'] : 'shield';
			$h   .= '<div class="mcp-card"><span class="mcp-ic" data-icon="' . esc_attr( $icon ) . '"></span>'
				. '<h3>' . esc_html( isset( $c['title'] ) ? $c['title'] : '' ) . '</h3>'
				. '<p>' . esc_html( isset( $c['text'] ) ? $c['text'] : '' ) . '</p></div>';
		}
		$h .= '</div></div></section>';
		return $h;
	}

	public function render_faq( $atts ) {
		$a     = shortcode_atts(
			array(
				'eyebrow' => 'FAQs',
				'heading' => '',
				'surface' => 'yes',
				'items'   => '',
			),
			$atts
		);
		$items = $this->group( $a['items'] );
		$h     = $this->css_once();
		$h    .= '<section class="mcp-blk mcp-section mcp-faq-sec ' . ( 'yes' === $a['surface'] ? 'mcp-surface' : '' ) . '"><div class="mcp-wrap">';
		$h    .= $this->eyebrow( $a['eyebrow'], 'c' );
		if ( '' !== $a['heading'] ) {
			$h .= '<h2 class="mcp-h2 mcp-center">' . esc_html( $a['heading'] ) . '</h2>';
		}
		$h .= '<div class="mcp-faq">';
		$first = true;
		foreach ( $items as $it ) {
			$open  = $first ? ' open' : '';
			$first = false;
			$h    .= '<details' . $open . '><summary>' . esc_html( isset( $it['q'] ) ? $it['q'] : '' )
				. '<span class="mcp-faqx"></span></summary><div class="mcp-faqa">'
				. wp_kses_post( isset( $it['a'] ) ? $it['a'] : '' ) . '</div></details>';
		}
		$h .= '</div></div></section>';
		return $h;
	}

	public function render_cta( $atts ) {
		$a  = shortcode_atts(
			array(
				'eyebrow' => '',
				'heading' => '',
				'sub'     => '',
				'buttons' => '',
			),
			$atts
		);
		$h  = $this->css_once();
		$h .= '<section class="mcp-blk mcp-cta"><div class="mcp-wrap mcp-center">';
		$h .= $this->eyebrow( $a['eyebrow'], 'c' );
		if ( '' !== $a['heading'] ) {
			$h .= '<h2 class="mcp-h2 mcp-cta-h">' . esc_html( $a['heading'] ) . '</h2>';
		}
		if ( '' !== $a['sub'] ) {
			$h .= '<p class="mcp-cta-sub">' . esc_html( $a['sub'] ) . '</p>';
		}
		$btns = $this->buttons( $this->group( $a['buttons'] ) );
		if ( $btns ) {
			$h .= '<div class="mcp-actions c">' . $btns . '</div>';
		}
		$h .= '</div></section>';
		return $h;
	}

	/* --------------------------------------------------------------------- */
	/* vc_map definitions (makes them editable elements in the builder)       */
	/* --------------------------------------------------------------------- */

	public function map() {
		if ( ! function_exists( 'vc_map' ) ) {
			return;
		}
		$icon_values = array(
			'Shield (safety)' => 'shield',
			'Sun'             => 'sun',
			'Layers (modular)'=> 'layers',
			'Bolt (backup)'   => 'bolt',
			'Gauge (efficient)'=> 'gauge',
			'Medal (warranty)'=> 'medal',
			'Battery'         => 'battery',
			'Charger'         => 'charger',
		);
		$btn_styles = array(
			'Primary (orange)' => 'primary',
			'Green'            => 'green',
			'Ghost (outline)'  => 'ghost',
		);

		vc_map(
			array(
				'name'        => 'MCP Hero',
				'base'        => 'mcp_hero',
				'category'    => 'MCP Blocks',
				'icon'        => 'icon-wpb-ui-custom_heading',
				'description' => 'Theme-proof hero: two-tone heading, buttons, trust pills, optional comparison card.',
				'params'      => array(
					array( 'type' => 'textfield', 'heading' => 'Eyebrow', 'param_name' => 'eyebrow' ),
					array( 'type' => 'textfield', 'heading' => 'Heading', 'param_name' => 'heading', 'admin_label' => true ),
					array( 'type' => 'textfield', 'heading' => 'Heading accent (orange line)', 'param_name' => 'heading_accent' ),
					array( 'type' => 'textarea', 'heading' => 'Sub text', 'param_name' => 'sub' ),
					array(
						'type' => 'param_group', 'heading' => 'Buttons', 'param_name' => 'buttons',
						'params' => array(
							array( 'type' => 'textfield', 'heading' => 'Label', 'param_name' => 'label' ),
							array( 'type' => 'textfield', 'heading' => 'URL', 'param_name' => 'url' ),
							array( 'type' => 'dropdown', 'heading' => 'Style', 'param_name' => 'style', 'value' => $btn_styles ),
						),
					),
					array(
						'type' => 'param_group', 'heading' => 'Trust pills', 'param_name' => 'pills',
						'params' => array( array( 'type' => 'textfield', 'heading' => 'Text (HTML ok)', 'param_name' => 'text' ) ),
					),
					array( 'type' => 'textfield', 'heading' => 'Card title', 'param_name' => 'card_title', 'group' => 'Comparison card' ),
					array(
						'type' => 'param_group', 'heading' => 'Card bars', 'param_name' => 'bars', 'group' => 'Comparison card',
						'params' => array(
							array( 'type' => 'textfield', 'heading' => 'Label', 'param_name' => 'label' ),
							array( 'type' => 'textfield', 'heading' => 'Note (right)', 'param_name' => 'note' ),
							array( 'type' => 'textfield', 'heading' => 'Fill label', 'param_name' => 'fill_label' ),
							array( 'type' => 'textfield', 'heading' => 'Percent (0-100)', 'param_name' => 'pct' ),
							array( 'type' => 'checkbox', 'heading' => 'Highlighted?', 'param_name' => 'lit', 'value' => array( 'Yes' => 'true' ) ),
						),
					),
					array( 'type' => 'textfield', 'heading' => 'Card footnote', 'param_name' => 'card_note', 'group' => 'Comparison card' ),
				),
			)
		);

		vc_map(
			array(
				'name'        => 'MCP Feature Cards',
				'base'        => 'mcp_cards',
				'category'    => 'MCP Blocks',
				'icon'        => 'icon-wpb-ui-custom_heading',
				'description' => 'Icon-tile feature cards in a responsive grid.',
				'params'      => array(
					array( 'type' => 'textfield', 'heading' => 'Eyebrow', 'param_name' => 'eyebrow' ),
					array(
						'type' => 'dropdown', 'heading' => 'Eyebrow colour', 'param_name' => 'eyebrow_color',
						'value' => array( 'Orange' => 'orange', 'Green' => 'green' ),
					),
					array( 'type' => 'textfield', 'heading' => 'Heading', 'param_name' => 'heading', 'admin_label' => true ),
					array( 'type' => 'textarea', 'heading' => 'Lede', 'param_name' => 'lede' ),
					array(
						'type' => 'dropdown', 'heading' => 'Background', 'param_name' => 'surface',
						'value' => array( 'Surface (light grey)' => 'yes', 'White' => 'no' ),
					),
					array(
						'type' => 'param_group', 'heading' => 'Cards', 'param_name' => 'cards',
						'params' => array(
							array( 'type' => 'dropdown', 'heading' => 'Icon', 'param_name' => 'icon', 'value' => $icon_values ),
							array( 'type' => 'textfield', 'heading' => 'Title', 'param_name' => 'title' ),
							array( 'type' => 'textarea', 'heading' => 'Text', 'param_name' => 'text' ),
						),
					),
				),
			)
		);

		vc_map(
			array(
				'name'        => 'MCP FAQ',
				'base'        => 'mcp_faq',
				'category'    => 'MCP Blocks',
				'icon'        => 'icon-wpb-ui-custom_heading',
				'description' => 'Accordion FAQ (native <details>, no JS needed).',
				'params'      => array(
					array( 'type' => 'textfield', 'heading' => 'Eyebrow', 'param_name' => 'eyebrow' ),
					array( 'type' => 'textfield', 'heading' => 'Heading', 'param_name' => 'heading', 'admin_label' => true ),
					array(
						'type' => 'dropdown', 'heading' => 'Background', 'param_name' => 'surface',
						'value' => array( 'Surface (light grey)' => 'yes', 'White' => 'no' ),
					),
					array(
						'type' => 'param_group', 'heading' => 'Q&A items', 'param_name' => 'items',
						'params' => array(
							array( 'type' => 'textfield', 'heading' => 'Question', 'param_name' => 'q' ),
							array( 'type' => 'textarea', 'heading' => 'Answer (HTML ok)', 'param_name' => 'a' ),
						),
					),
				),
			)
		);

		vc_map(
			array(
				'name'        => 'MCP CTA',
				'base'        => 'mcp_cta',
				'category'    => 'MCP Blocks',
				'icon'        => 'icon-wpb-ui-custom_heading',
				'description' => 'Navy call-to-action band with sun-glow.',
				'params'      => array(
					array( 'type' => 'textfield', 'heading' => 'Eyebrow', 'param_name' => 'eyebrow' ),
					array( 'type' => 'textfield', 'heading' => 'Heading', 'param_name' => 'heading', 'admin_label' => true ),
					array( 'type' => 'textarea', 'heading' => 'Sub text', 'param_name' => 'sub' ),
					array(
						'type' => 'param_group', 'heading' => 'Buttons', 'param_name' => 'buttons',
						'params' => array(
							array( 'type' => 'textfield', 'heading' => 'Label', 'param_name' => 'label' ),
							array( 'type' => 'textfield', 'heading' => 'URL', 'param_name' => 'url' ),
							array( 'type' => 'dropdown', 'heading' => 'Style', 'param_name' => 'style', 'value' => $btn_styles ),
						),
					),
				),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Bundled stylesheet (tokens + all blocks). Printed once.                */
	/* --------------------------------------------------------------------- */

	private function icon_svg( $name ) {
		$o = '%23F2731C';
		$paths = array(
			'shield'  => "%3Cpath d='M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z'/%3E%3Cpath d='m9 12 2 2 4-4'/%3E",
			'sun'     => "%3Ccircle cx='12' cy='12' r='4'/%3E%3Cpath d='M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2'/%3E",
			'layers'  => "%3Crect x='5' y='3' width='14' height='5' rx='1'/%3E%3Crect x='5' y='10' width='14' height='5' rx='1'/%3E%3Crect x='5' y='17' width='14' height='4' rx='1'/%3E",
			'bolt'    => "%3Cpath d='M13 2 4 14h7l-1 8 9-12h-7l1-8Z'/%3E",
			'gauge'   => "%3Cpath d='M4 14a8 8 0 0 1 4-6.9'/%3E%3Cpath d='M12 14a8 8 0 0 1 8-8'/%3E%3Cpath d='M12 14 16 9'/%3E%3Ccircle cx='12' cy='14' r='1.6'/%3E",
			'medal'   => "%3Ccircle cx='12' cy='9' r='6'/%3E%3Cpath d='M9 14.5 8 22l4-2 4 2-1-7.5'/%3E",
			'battery' => "%3Crect x='3' y='7' width='16' height='11' rx='2'/%3E%3Cpath d='M19 10h2v5h-2M7 11v3M11 11v3'/%3E",
			'charger' => "%3Cpath d='M7 21V6a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v15M5 21h12M15 11h2.5a1.5 1.5 0 0 1 1.5 1.5V15a1.5 1.5 0 0 0 3 0V9l-2-2'/%3E",
		);
		$p = isset( $paths[ $name ] ) ? $paths[ $name ] : $paths['shield'];
		return "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='$o' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'%3E$p%3C/svg%3E\")";
	}

	private function base_css() {
		$t    = $this->tokens();
		$mono = "ui-monospace,'SF Mono',Menlo,'Roboto Mono',monospace";
		$grad = "linear-gradient(135deg,rgba(242,115,28,.14),rgba(242,115,28,.06))";
		$sun  = "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23F48539' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='12' cy='12' r='4'/%3E%3Cpath d='M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2'/%3E%3C/svg%3E\")";
		$icons = '';
		foreach ( array( 'shield', 'sun', 'layers', 'bolt', 'gauge', 'medal', 'battery', 'charger' ) as $n ) {
			$icons .= ".mcp-ic[data-icon=\"$n\"]{background-image:" . $this->icon_svg( $n ) . ",$grad;}";
		}

		return "
.mcp-blk{--navy:{$t['navy']};--ink:{$t['ink']};--body:{$t['body']};--muted:{$t['muted']};--orange:{$t['orange']};--orange-lt:{$t['orange_lt']};--orange-dk:{$t['orange_dk']};--green:{$t['green']};--surface:{$t['surface']};--line:{$t['line']};
  font-family:inherit;box-sizing:border-box;width:100vw;max-width:100vw;margin-left:calc(50% - 50vw);position:relative;}
.mcp-blk *{box-sizing:border-box;}
.mcp-wrap{max-width:{$t['maxw']};margin:0 auto;padding:0 clamp(1.15rem,4vw,2.5rem);}
.mcp-section{padding:clamp(4rem,8vw,6.5rem) 0;background:#fff;}
.mcp-surface{background:var(--surface);}
.mcp-center{text-align:center;margin-left:auto;margin-right:auto;}
.mcp-eyebrow{margin:0 0 16px;}
.mcp-eyebrow span{font-family:$mono;font-size:12px;letter-spacing:.2em;text-transform:uppercase;color:var(--orange);font-weight:600;display:inline-flex;align-items:center;gap:.7em;}
.mcp-eyebrow span:before{content:'';width:26px;height:2px;background:var(--orange);border-radius:2px;}
.mcp-eyebrow.green span{color:var(--green);} .mcp-eyebrow.green span:before{background:var(--green);}
.mcp-eyebrow.c{text-align:center;}
.mcp-h1{color:var(--navy);font-weight:800;font-size:clamp(40px,5vw,68px);line-height:1.03;letter-spacing:-.035em;margin:0;}
.mcp-h1 em{display:block;font-style:normal;color:var(--orange);}
.mcp-h2{color:var(--ink);font-weight:800;font-size:clamp(30px,3vw,46px);line-height:1.06;letter-spacing:-.02em;margin:0;}
.mcp-sub{color:var(--body);font-size:18px;line-height:1.6;max-width:46ch;margin:18px 0 0;}
.mcp-lede{color:var(--body);font-size:18px;line-height:1.6;max-width:58ch;margin:14px 0 0;}
/* buttons */
.mcp-actions{margin-top:24px;display:flex;flex-wrap:wrap;gap:12px;}
.mcp-actions.c{justify-content:center;}
.mcp-btn{display:inline-flex;align-items:center;gap:.5em;font-weight:700;font-size:15.5px;padding:14px 24px;border-radius:10px;text-decoration:none;border:2px solid transparent;transition:transform .18s,box-shadow .18s,background .18s;cursor:pointer;}
.mcp-btn-primary{background:var(--orange);color:#fff;box-shadow:0 12px 26px -12px rgba(242,115,28,.7);}
.mcp-btn-primary:hover{background:var(--orange-dk);transform:translateY(-2px);}
.mcp-btn-green{background:var(--green);color:#fff;box-shadow:0 12px 26px -14px rgba(55,150,79,.7);}
.mcp-btn-green:hover{background:{$t['green_dk']};transform:translateY(-2px);}
.mcp-btn-ghost{background:transparent;color:var(--navy);border-color:var(--line);}
.mcp-btn-ghost:hover{border-color:var(--navy);transform:translateY(-2px);}
.mcp-cta .mcp-btn-ghost{color:#fff;border-color:rgba(255,255,255,.4);}
/* hero */
.mcp-hero{background:#fff;overflow:hidden;padding:clamp(3rem,6vw,5rem) 0;}
.mcp-hero:before{content:'';position:absolute;inset:0;z-index:0;background:radial-gradient(60% 50% at 88% 6%,rgba(242,115,28,.16),transparent 60%),radial-gradient(50% 40% at 6% 92%,rgba(55,150,79,.07),transparent 60%);}
.mcp-hero:after{content:'';position:absolute;inset:0;z-index:0;opacity:.5;background-image:radial-gradient(rgba(11,38,72,.10) 1.3px,transparent 1.4px);background-size:26px 26px;-webkit-mask-image:linear-gradient(180deg,transparent,#000 40%,#000 75%,transparent);mask-image:linear-gradient(180deg,transparent,#000 40%,#000 75%,transparent);}
.mcp-hero .mcp-wrap{position:relative;z-index:1;}
.mcp-hero-grid{display:grid;grid-template-columns:1.05fr .95fr;gap:clamp(2rem,5vw,4rem);align-items:center;}
.mcp-trust{margin-top:26px;display:flex;flex-wrap:wrap;gap:10px;}
.mcp-pill{display:inline-flex;align-items:center;gap:.5em;padding:8px 14px;border-radius:999px;border:1px solid var(--line);background:#fff;box-shadow:0 10px 30px -18px rgba(11,38,72,.3);font-family:$mono;font-size:12.5px;letter-spacing:.03em;color:var(--navy);}
.mcp-pill b{font-weight:700;}
.mcp-scard{background:var(--navy);color:#fff;border-radius:22px;padding:30px;box-shadow:0 22px 50px -28px rgba(11,38,72,.42);position:relative;overflow:hidden;}
.mcp-scard:after{content:'';position:absolute;right:-40px;top:-40px;width:160px;height:160px;border-radius:50%;background:radial-gradient(circle,rgba(242,115,28,.18),transparent 70%);}
.mcp-scard-t{display:flex;align-items:center;gap:.6em;font-size:18px;font-weight:700;color:#fff;margin-bottom:22px;position:relative;z-index:1;}
.mcp-scard-sun{width:24px;height:24px;flex:none;background:$sun no-repeat center/contain;}
.mcp-crow{margin-bottom:18px;position:relative;z-index:1;}
.mcp-crow-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;}
.mcp-crow-l{font-weight:600;color:#fff;font-size:15px;}
.mcp-crow-p{font-family:$mono;font-size:13px;color:var(--muted);}
.mcp-lit .mcp-crow-p{color:var(--orange-lt);}
.mcp-cbar{position:relative;height:38px;border-radius:10px;background:rgba(255,255,255,.07);overflow:hidden;}
.mcp-fill{position:absolute;top:0;bottom:0;left:0;border-radius:10px;display:flex;align-items:center;padding-left:14px;font-size:13px;font-weight:600;color:#fff;white-space:nowrap;}
.mcp-fill-dim{background:rgba(255,255,255,.16);}
.mcp-fill-lit{background:linear-gradient(90deg,var(--orange-dk),var(--orange));box-shadow:0 0 30px rgba(242,115,28,.25);}
.mcp-scard-note{font-size:13px;color:var(--muted);margin:14px 0 0;position:relative;z-index:1;}
/* feature cards */
.mcp-grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;margin-top:30px;}
.mcp-card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:28px 26px;box-shadow:0 10px 30px -18px rgba(11,38,72,.3);transition:transform .2s,box-shadow .2s,border-color .2s;}
.mcp-card:hover{transform:translateY(-4px);box-shadow:0 22px 50px -28px rgba(11,38,72,.42);border-color:#dbe3ee;}
.mcp-ic{display:block;width:50px;height:50px;border-radius:14px;border:1px solid rgba(242,115,28,.22);margin-bottom:14px;background-repeat:no-repeat;background-position:center,center;background-size:24px,cover;}
$icons
.mcp-card h3{color:var(--ink);font-weight:800;font-size:18px;margin:0 0 8px;letter-spacing:-.01em;}
.mcp-card p{color:var(--body);font-size:15.5px;line-height:1.55;margin:0;}
/* faq */
.mcp-faq-sec .mcp-eyebrow.c span{justify-content:center;}
.mcp-faq{max-width:840px;margin:28px auto 0;display:grid;gap:10px;}
.mcp-faq details{background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden;box-shadow:0 10px 30px -18px rgba(11,38,72,.3);}
.mcp-faq summary{list-style:none;cursor:pointer;padding:18px 22px;display:flex;justify-content:space-between;align-items:center;gap:1rem;font-weight:700;font-size:16.5px;color:var(--ink);}
.mcp-faq summary::-webkit-details-marker{display:none;}
.mcp-faqx{flex:none;width:26px;height:26px;border-radius:50%;border:1.5px solid var(--orange);position:relative;transition:transform .25s,background .25s;}
.mcp-faqx:before{content:'+';position:absolute;inset:0;display:grid;place-items:center;font-family:$mono;font-size:18px;line-height:1;color:var(--orange);}
.mcp-faq details[open] .mcp-faqx{transform:rotate(45deg);background:var(--orange);}
.mcp-faq details[open] .mcp-faqx:before{color:#fff;}
.mcp-faqa{padding:0 22px 18px;color:var(--body);font-size:15.5px;line-height:1.6;}
.mcp-faqa a{color:var(--orange);font-weight:600;}
/* cta */
.mcp-cta{background:var(--navy);overflow:hidden;padding:clamp(4rem,8vw,6.5rem) 0;}
.mcp-cta:before{content:'';position:absolute;inset:0;z-index:0;background:radial-gradient(60% 120% at 50% 120%,rgba(242,115,28,.18),transparent 60%);}
.mcp-cta:after{content:'';position:absolute;inset:0;z-index:0;opacity:.5;background-image:radial-gradient(rgba(255,255,255,.10) 1.2px,transparent 1.3px);background-size:26px 26px;-webkit-mask-image:radial-gradient(70% 120% at 50% 0%,#000,transparent 70%);mask-image:radial-gradient(70% 120% at 50% 0%,#000,transparent 70%);}
.mcp-cta .mcp-wrap{position:relative;z-index:1;}
.mcp-cta .mcp-eyebrow.c span{color:var(--orange-lt);justify-content:center;}
.mcp-cta-h{color:#fff;text-align:center;max-width:20ch;margin:0 auto;}
.mcp-cta-sub{color:var(--muted);font-size:18px;max-width:52ch;margin:16px auto 26px;text-align:center;}
@media(max-width:860px){.mcp-hero-grid{grid-template-columns:1fr;}.mcp-grid3{grid-template-columns:1fr;}}
@media(min-width:861px) and (max-width:1040px){.mcp-grid3{grid-template-columns:repeat(2,1fr);}}
";
	}
}
