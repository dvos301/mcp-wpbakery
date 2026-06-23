# MCP WPBakery — agent guide

This repo is an MCP server + WordPress plugin that lets an AI agent read a
WPBakery site's `vc_map` element registry and build **native, editable**
WPBakery elements. When you build or edit pages through the `wpbakery_*` tools,
follow these rules — they are the whole point of the tool.

## Rules for building WPBakery content

1. **Native elements only.** Use real WPBakery elements (`vc_row`, `vc_column`,
   `vc_tta_accordion`, `vc_custom_heading`, `vc_btn`, `vc_single_image`, …).
   **Never** lay out content with a `vc_raw_html` block — it becomes one opaque,
   uneditable element in the builder, which defeats the purpose.
2. **Discover before building.** `wpbakery_list_elements` → `wpbakery_element_schema`
   for exact params. Use the site's own custom/theme elements when relevant.
3. **Style via Page CSS, not HTML.** Give elements an `el_class` and put CSS in
   the page's Custom CSS via `wpbakery_set_page_css` (or `update_page(page_css=…)`).
   Never embed `<style>` through a Raw HTML element.
4. **Themes re-render elements.** Impreza/us-core renders accordions as `.w-tabs`
   markup (`.w-tabs-section-header`, `.w-tabs-section-title`, `.w-tabs-section-control`,
   `.w-tabs-section-content-h`), not `.vc_tta-*`. Fetch the live rendered HTML,
   target the real classes, and use `!important` to beat theme CSS.
5. **Safe writes.** Always `wpbakery_validate` first. Build on a **draft**,
   review, then publish. Every write auto-saves a revision.

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
