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
2. **Watch for leaking elements; use safe defaults.** On Impreza/us-core,
   `vc_btn` **and `vc_icon`** can render as literal `[vc_btn]`/`[vc_icon]` text
   (especially uncached). The theme's own `us_btn`/`us_iconbox`/`us_image` render
   but are **not in `vc_map`** (so `wpbakery_validate` flags them — verify with
   `render_preview`). Bulletproof, full-control alternatives:
   - **icons** → a CSS background-SVG (`data:image/svg+xml` in page CSS) on a
     native element (e.g. a heading `::before`). Pixel-perfect, no font/shortcode
     dependency.
   - **buttons** → a styled `<a>` inside a `vc_column_text`, skinned via page CSS.
     Validate-clean and reliable.
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
   `--height 6500`. **Preferred when available:** the chrome-devtools MCP —
   `navigate_page` to the `preview_url`, `evaluate_script` to read the live DOM
   (find the real classes, diagnose the gap), then `take_screenshot`. Append a
   cache-buster (`&cb=1`) to the URL — WP Rocket caches the GET. `render_preview`
   itself now fetches the **uncached** render so leaking shortcodes are caught.
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

## Brand tokens (context, not content)

Pull the site's real design language **once** and reuse it so every build is
on-brand. Store it as a `brand` block in `clients/<slug>.json` (shape in
`clients/example.json`); inject it as page-CSS variables, e.g.
`.scope{ --navy:#002a52; --accent:#f07d00; … }`, and reference the vars.

Extract from the live site with the chrome-devtools MCP (`navigate_page` →
`evaluate_script` over `getComputedStyle`): read `h1/h2/body/p`, a button
(`.w-btn`), and the container (`.l-section-h`) — family, size, weight,
line-height, colours, container width, section padding. **Vista's real tokens:**
Montserrat; navy `#002a52`, accent `#f07d00`, ink `#0e2236`, muted `#9db4cf`;
container 1185 (max 1280); section padding 96; buttons uppercase, ~3px radius.
(The live navy is `#002a52`, not the older `#20283a`.)

## Impreza translation cheatsheet (target the theme's real markup)

The theme re-renders your shortcodes — style **its** classes, not WPBakery's:

| You build | Impreza renders | Style this |
|-----------|-----------------|------------|
| `vc_row` (el_class `x`) | `section.l-section.x` wrapping `.l-section-h` | bg/clip-path on `.x`; the **container** is `.l-section-h` (force `max-width` + `margin:0 auto` — pages can render full-bleed) |
| columns | a **CSS grid** `.g-cols.cols_N` | for custom/asymmetric widths set `grid-template-columns` **on `.g-cols`** — NOT column `width` (that shrinks the item *inside* its track) |
| `vc_tta_accordion` | `.w-tabs` (`.w-tabs-section-header/-title/-control/-content-h`) | those, with `!important` |
| `vc_custom_heading` | `div.vc_custom_heading.x` (text directly inside; set `use_theme_fonts="yes"`) | `.x` |
| `vc_column_text` | `.wpb_text_column.x > .wpb_wrapper > p` | `.x`, `.x p` |

Verified to work natively: overlap / `z-index` / `clip-path` angled dividers /
negative-margin straddling cards. Full-bleed inside the padded container:
`margin-right: calc((container/2 − pad) − 50vw)` (≈ `calc(562.5px − 50vw)` for
1185 + 30px pad). Untested gap: a real **editable photo** (needs media upload).

## The fidelity loop (design → native, world-class)

Hit high fidelity with a tight loop, not one big guess:

1. **Target.** Get the design (HTML/CSS, image, or description), render it and
   screenshot it as the benchmark. Reproduce *that* — never substitute an old
   block, never fake it with `vc_raw_html`.
2. **Build native** from real elements + brand tokens → `validate` →
   `update_page` → `set_page_css`.
3. **Preview** with `render_preview` (check `unrendered_shortcodes == []`) and
   **screenshot** the `preview_url`.
4. **Score on two axes:** (a) *fidelity* — layout, type, spacing, colour vs the
   target; (b) *nativeness* (hard gate) — no `vc_raw_html`, nothing leaked,
   validates. Diagnose the gap by inspecting the live DOM.
5. **Fix and repeat** (CSS-only iterations are instant via `set_page_css`) until
   it matches or plateaus. On a genuine native ceiling, **report it honestly** —
   don't cheat with raw HTML to hide it.

Loop scratch (screenshots, references) lives in `.loop/` (git-ignored).

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
