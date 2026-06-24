# MCP WPBakery — agent guide

This repo is an MCP server + WordPress plugin that lets an AI agent read a
WPBakery site's `vc_map` element registry and build **native, editable**
WPBakery elements. When you build or edit pages through the `wpbakery_*` tools,
follow these rules — they are the whole point of the tool.

## Rules for building WPBakery content

> **The authoritative, non-negotiable ruleset is [`WPBAKERY_BUILD_RULES.md`](WPBAKERY_BUILD_RULES.md).**
> It is loaded into the MCP server's `INSTRUCTIONS` at startup, so it is injected
> into every session that uses the `wpbakery_*` tools. Read it before building.
> The summary below is a quick reference; the full doc covers the granularity
> contract, the decomposition map, the anti-pattern, value encoding, the
> self-audit gate, and custom-element authoring.

1. **Native elements only.** Use real WPBakery elements (`vc_row`, `vc_column`,
   `vc_tta_accordion`, `vc_custom_heading`, `vc_single_image`, …).
   **Never** lay out content with a `vc_raw_html` block — it becomes one opaque,
   uneditable element in the builder, which defeats the purpose.
2. **Prefer the theme's own elements.** When a theme ships its own element, use it
   over the WPBakery twin — themes often only render their own. On Impreza/us-core
   use `us_btn` (NOT `vc_btn`, which renders as literal `[vc_btn]` text), `us_iconbox`,
   `us_image`. Confirm with `wpbakery_render_preview` (see rule 6).
3. **Discover before building.** `wpbakery_list_elements` (summary) →
   `wpbakery_element_schema` for exact params. Use the site's custom/theme elements.
4. **Style via Page CSS — not HTML, not `css=` design-options.** Give elements an
   `el_class` and style via `wpbakery_set_page_css` / `wpbakery_append_page_css`
   (or `update_page(page_css=…)`). Impreza ignores the WPBakery `css=` attribute.
   Page CSS now writes all theme keys (`_wpb_/usb_/vc_post_custom_css`) and busts
   caches automatically.
5. **Themes re-render elements.** Impreza/us-core renders accordions as `.w-tabs`
   markup (`.w-tabs-section-header`, `.w-tabs-section-title`, `.w-tabs-section-control`,
   `.w-tabs-section-content-h`), not `.vc_tta-*`. Use the preview to find real
   classes; use `!important` to beat theme CSS.
6. **Always preview after writing.** A plain read's `content.rendered` lies. Call
   `wpbakery_render_preview` — it returns `unrendered_shortcodes` (elements the
   theme dropped) and a public `preview_url` (drafts too). To *see* it: GET the
   URL for the HTML, and **screenshot it** with `python screenshot.py
   "<preview_url>" shot.png` (handles the headless-Chrome hangs), then Read the
   PNG to judge visual alignment with what the user asked for. Long page? add
   `--height 6500`.
7. **Safe writes & iteration.** `wpbakery_validate` first. Build on a **draft**
   (`wpbakery_create_page`) → preview → fix → `wpbakery_set_status` publish. Set
   SEO/noindex via `wpbakery_set_post_meta`. Iterate cheaply with
   `wpbakery_append_page_css` and `wpbakery_replace_in_content`. Every write saves a revision.

## Worked example (the canonical pattern): a shadcn-style FAQ

1. `wpbakery_get_page` the source page; locate the existing FAQ; note it's a
   native `vc_tta_accordion`.
2. Build a native accordion: `vc_custom_heading` + `vc_tta_accordion`
   (`style="outline" shape="rounded" color="white" c_icon="chevron"
   el_class="mcp-shadcn-faq"`) with one editable `vc_tta_section` per question.
3. `wpbakery_validate`, then `wpbakery_update_page` onto a **draft**.
4. `wpbakery_set_page_css` with CSS scoped to `.mcp-shadcn-faq` targeting the
   theme's `.w-tabs-*` classes (border, dividers, header padding/typography,
   recolor the chevron, muted answer text) — `!important` where it fights theme.
5. Review the draft; iterate styling with `set_page_css` (instant, no redeploy).
6. When approved, write to the live page.

## Architecture

- `wp-plugin/` — the WordPress plugin (engine). Reads `vc_map`, parses/serializes
  shortcodes, validates, regenerates `_wpb_shortcodes_custom_css`, and writes
  page custom CSS (`_wpb_post_custom_css`). Exposed via WP-CLI + REST.
- `server/` — Python MCP server. `transport.py` dispatches to `_rest.py`
  (HTTP + Application Password) or `_ssh.py` (WP-CLI). Configs via
  `clientconfig.py` (`clients/<slug>.json` or env).

## Local plugin testing (no production needed)

There is a Local site (`vista-test`, WPBakery 8.7.1 + Impreza) usable for
bootstrap-rendering and PHP-level tests. See memory `mcp-wpbakery-project` for
the exact MySQL/bootstrap recipe and gotchas (POST to bypass WP Rocket cache;
`cd` into WP root for relative `wp-salt.php`).

After any plugin PHP change, re-run `server/pack.py` and re-upload the zip
(REST sites) or `deploy.py` (SSH sites). Bump the version so `wpbakery_ping`
confirms the new build.
