"""MCP server for WPBakery Page Builder.

Lets an AI agent read a site's live vc_map element registry and build / edit
native, fully-editable WPBakery elements on WordPress pages.

Transport: SSH + WP-CLI to the companion "MCP WPBakery Bridge" plugin.
Client sites are resolved by slug from the SEO toolkit
(~/seo_toolkit/clients/<slug>.json).

Run:
    python -m mcp_wpbakery.server          # stdio MCP server

Register in Claude Code (.mcp.json / claude mcp add):
    "wpbakery": { "command": "python", "args": ["-m", "mcp_wpbakery.server"] }
"""

from __future__ import annotations

import json
from typing import Any

from mcp.server.fastmcp import FastMCP

from . import transport as t

INSTRUCTIONS = """\
Build and edit pages on WordPress + WPBakery. The point is NATIVE, editable
elements that reproduce the REQUESTED design faithfully.

FIDELITY FIRST (the whole job):
- Reproduce the design you were given (HTML/CSS, an image, or a description).
  Do NOT substitute a remembered/old block for it, and do NOT drop a vc_raw_html
  block to fake the look — both are cheating. Reuse a pattern only when it truly
  matches the target, and adapt it.
- Use the site's real brand. If the client config has a "brand" block, inject
  those tokens as page-CSS variables (:root/scope) so the build is on-brand.
  Otherwise extract them once from the live site (fonts, colours, container
  width) before styling.

CORE RULES:
1. NATIVE ELEMENTS ONLY. vc_row / vc_column / vc_row_inner / vc_column_inner /
   vc_custom_heading (set use_theme_fonts="yes" or it defaults to Abril Fatface)
   / vc_column_text / vc_tta_accordion ... Never lay out with vc_raw_html.

2. SOME ELEMENTS LEAK — USE SAFE DEFAULTS. On Impreza/us-core, vc_btn AND
   vc_icon can render as literal "[vc_btn]"/"[vc_icon]" text (especially
   uncached). The theme's us_btn/us_iconbox render but are NOT in vc_map (so
   validate flags them; verify with render_preview). Bulletproof, full-control
   alternatives:
     • icons  -> a CSS background-SVG (data:image/svg+xml in page CSS) on a
       native element, e.g. a heading ::before. Pixel-perfect, no font/shortcode
       dependency.
     • buttons-> a styled <a> inside vc_column_text
       ([vc_column_text]<a class="btn">Label</a>[/vc_column_text]) skinned via
       page CSS. Validate-clean and reliable.

3. DISCOVER FIRST. wpbakery_list_elements -> wpbakery_element_schema for params.

4. STYLE VIA PAGE CSS — never inline HTML, never the css= design-options attr
   (Impreza ignores it). Give elements an el_class; style via set_page_css /
   append_page_css / update_page(page_css). Page CSS writes all theme CSS keys
   and busts caches automatically.

5. THE THEME RE-RENDERS YOUR MARKUP — target ITS classes (render_preview, then
   inspect the live DOM):
     • Container is `.l-section-h` (force max-width + margin:0 auto — pages can
       render full-bleed).
     • Columns are a CSS GRID `.g-cols.cols_N`. For custom/asymmetric widths set
       `grid-template-columns` ON `.g-cols` — NOT column `width` (that shrinks the
       item inside its grid track).
     • Accordions render as `.w-tabs-*`, not `.vc_tta-*`.
   Overlap, z-index, clip-path and negative margins all work natively (straddling
   cards, angled section dividers are fine). Full-bleed inside the padded
   container: margin-right: calc((container/2 - pad) - 50vw).

6. ALWAYS render_preview AFTER WRITING, then SCREENSHOT and look — a plain
   read's content.rendered lies. render_preview returns the true UNCACHED
   front-end HTML, unrendered_shortcodes (fix any), and a tokenized preview_url.
   Screenshot it (the site header + page-title band are theme chrome, not your
   section). Prefer the chrome-devtools MCP: navigate -> evaluate_script to read
   the live DOM (find the real classes / diagnose the gap) -> take_screenshot.

7. SAFE WRITES. validate -> create_page(draft) -> render_preview -> fix ->
   set_status(publish | trash). Every write saves a revision. Set noindex via
   set_post_meta (rank_math_robots = ["noindex","nofollow"]).

8. ITERATE CHEAPLY. set_page_css for CSS-only changes (instant, no content
   rewrite); replace_in_content for surgical content edits.

THE FIDELITY LOOP (how to reach world-class):
target design -> build native -> validate -> update_page -> set_page_css ->
render_preview (unrendered_shortcodes == []?) -> screenshot the preview_url ->
compare to the target on TWO axes: (a) fidelity — layout, type, spacing, colour;
(b) nativeness — no vc_raw_html, nothing leaked, validates. Diagnose the gap by
inspecting the live DOM, fix, repeat until it matches or plateaus. On a genuine
native ceiling, report it honestly — never cheat with raw HTML to hide it.
"""

mcp = FastMCP("wpbakery", instructions=INSTRUCTIONS)


def _wrap(fn, *args, **kwargs) -> str:
    """Run a transport call and return a JSON string, surfacing errors cleanly."""
    try:
        data = fn(*args, **kwargs)
        return json.dumps({"ok": True, "data": data}, ensure_ascii=False, indent=2)
    except t.RemoteError as e:
        return json.dumps({"ok": False, "error": f"WPBakery/WordPress error: {e}"})
    except t.TransportError as e:
        return json.dumps({"ok": False, "error": f"Connection error: {e}"})
    except Exception as e:  # noqa: BLE001
        return json.dumps({"ok": False, "error": f"{type(e).__name__}: {e}"})


@mcp.tool()
def wpbakery_ping(client: str) -> str:
    """Check connectivity to a client's WordPress site and confirm WPBakery is active.

    Args:
        client: SEO toolkit client slug (e.g. "lwa", "vista", "perthcheapmovers").

    Returns plugin version, WP version, and the detected WPBakery version.
    Always call this first when working with a new client.
    """
    return _wrap(t.ping, client)


@mcp.tool()
def wpbakery_list_elements(client: str) -> str:
    """List every WPBakery element registered on the site (the live vc_map).

    Includes core, theme-bundled, and add-on elements. Each entry has the
    shortcode tag, display name, category, container flags, and its full
    parameter schema (param_name, type, default, options, dependencies).

    Use this to discover what you can build. For one element's full schema,
    prefer wpbakery_element_schema to keep the payload small.

    Args:
        client: SEO toolkit client slug.
    """
    return _wrap(t.list_elements, client)


@mcp.tool()
def wpbakery_element_schema(client: str, tag: str) -> str:
    """Get the full parameter schema for ONE WPBakery element.

    Returns its params with types (textfield, dropdown, attach_image,
    textarea_html, colorpicker, css_editor, ...), defaults, dropdown options,
    and conditional dependencies — everything needed to build a valid instance.

    Args:
        client: SEO toolkit client slug.
        tag: Element shortcode base, e.g. "vc_btn", "vc_single_image".
    """
    return _wrap(t.element, client, tag)


@mcp.tool()
def wpbakery_list_pages(
    client: str,
    post_type: str = "page",
    search: str = "",
    limit: int = 100,
) -> str:
    """List pages/posts on the site, flagging which were built with WPBakery.

    Args:
        client: SEO toolkit client slug.
        post_type: Comma-separated post types, e.g. "page" or "page,post".
        search: Optional title/content search term.
        limit: Max rows (default 100).
    """
    return _wrap(
        t.list_posts,
        client,
        post_type=post_type or None,
        search=search or None,
        limit=limit,
    )


@mcp.tool()
def wpbakery_get_page(client: str, post_id: int) -> str:
    """Get a page's full WPBakery state: raw shortcode content, parsed structure
    tree, and the generated design-options CSS.

    The 'structure' field is a nested tree of {tag, atts, children/content} —
    the easiest form to reason about before editing.

    Args:
        client: SEO toolkit client slug.
        post_id: WordPress post/page ID.
    """
    return _wrap(t.get_post, client, post_id)


@mcp.tool()
def wpbakery_get_structure(client: str, post_id: int) -> str:
    """Get only the parsed shortcode structure tree for a page (no raw content/CSS).

    Args:
        client: SEO toolkit client slug.
        post_id: WordPress post/page ID.
    """
    return _wrap(t.structure, client, post_id)


@mcp.tool()
def wpbakery_build_element(
    client: str,
    tag: str,
    atts: dict[str, Any] | None = None,
    inner: str = "",
) -> str:
    """Build ONE spec-compliant, fully-editable WPBakery element shortcode
    (validated against vc_map). Does NOT write to the database — it returns the
    shortcode string plus any validation warnings, for you to assemble and
    later save with wpbakery_update_page.

    Args:
        client: SEO toolkit client slug.
        tag: Element shortcode base, e.g. "vc_btn".
        atts: Attribute map, e.g. {"title": "Contact us", "style": "flat"}.
              Param names/values must match the element schema (check it first).
        inner: Raw inner shortcode string for container/HTML-content elements
               (e.g. the column shortcodes inside a vc_row, or HTML for
               vc_column_text). Leave empty for self-closing elements.
    """
    return _wrap(t.build_element, client, tag, atts or None, inner)


@mcp.tool()
def wpbakery_validate(client: str, content: str) -> str:
    """Validate a WPBakery shortcode string against the site's vc_map without
    saving. Reports unknown elements (errors) and unknown params / invalid
    dropdown values (warnings). Run this before wpbakery_update_page.

    Args:
        client: SEO toolkit client slug.
        content: Full shortcode content to validate.
    """
    return _wrap(t.validate, client, content)


@mcp.tool()
def wpbakery_update_page(
    client: str,
    post_id: int,
    content: str,
    skip_validate: bool = False,
    page_css: str | None = None,
) -> str:
    """Write new WPBakery shortcode content to a page.

    This is the real write. It: (1) validates against vc_map unless
    skip_validate, (2) saves a revision of the current content as a backup,
    (3) writes the new content, (4) marks the page WPBakery-enabled,
    (5) regenerates the Design Options custom CSS, and (6) optionally sets the
    page's custom CSS (page_css).

    Build pages from NATIVE elements (vc_row/vc_column/vc_tta_accordion/etc.) so
    every part is editable in the WPBakery editor. To style native elements,
    pass page_css rather than embedding a Raw HTML block — that keeps the
    content editable while skinning it.

    Args:
        client: SEO toolkit client slug.
        post_id: WordPress post/page ID.
        content: Full new shortcode content for the page.
        skip_validate: Set true to write even if validation reports errors.
        page_css: Optional page-scoped CSS (WPBakery "Page Settings → Custom
                  CSS"). Use to skin native elements (e.g. target an el_class).
    """
    return _wrap(t.update_post, client, post_id, content, skip_validate, page_css)


@mcp.tool()
def wpbakery_set_page_css(client: str, post_id: int, css: str) -> str:
    """Set a page's WPBakery custom CSS (Page Settings → Custom CSS) without
    touching its content. The CSS is output in a <style> on that page and stays
    editable in the builder. Use this to skin native elements (e.g. give an
    accordion an el_class, then target it here) and to iterate on styling.

    Args:
        client: SEO toolkit client slug.
        post_id: WordPress post/page ID.
        css: The CSS to store for this page.
    """
    return _wrap(t.set_page_css, client, post_id, css)


@mcp.tool()
def wpbakery_append_page_css(client: str, post_id: int, css: str) -> str:
    """Append a CSS rule to a page's custom CSS without resending the whole sheet.
    Use this to iterate on styling cheaply. Writes all theme CSS keys + busts caches.

    Args:
        client: SEO toolkit client slug.
        post_id: WordPress post/page ID.
        css: The CSS rule(s) to append.
    """
    return _wrap(t.append_page_css, client, post_id, css)


@mcp.tool()
def wpbakery_render_preview(client: str, post_id: int) -> str:
    """Render a page through the front-end so the theme + content-elements actually
    run — the ONLY reliable way to see what will appear (drafts included). ALWAYS
    call this after writing.

    Returns:
      - preview_url: a public, tokenized URL (valid ~10 min) you can fetch (plain
        GET, no auth) or screenshot — even for a draft. The true themed render.
      - unrendered_shortcodes: registered elements the theme did NOT render (e.g.
        ["vc_btn"] on Impreza) — replace these with theme-native equivalents.
        Computed from the real front-end HTML (render_source="loopback").
      - rendered_excerpt / html_bytes / rendered_truncated: a capped peek at the
        rendered HTML (fetch preview_url for the full page).
      - render_source: "loopback" (real front-end) or "filter" (fallback).
      - page_css: the page's current custom CSS.

    Args:
        client: SEO toolkit client slug.
        post_id: WordPress post/page ID.
    """
    return _wrap(t.render_preview, client, post_id)


@mcp.tool()
def wpbakery_create_page(
    client: str,
    title: str,
    slug: str = "",
    status: str = "draft",
) -> str:
    """Create a new page (draft by default) and return its id, edit link, and URL.
    Build on a draft, preview it, then publish with wpbakery_set_status.

    Args:
        client: SEO toolkit client slug.
        title: Page title.
        slug: Optional URL slug (auto from title if omitted).
        status: draft | publish | pending | private (default draft).
    """
    return _wrap(t.create_page, client, title, slug, status)


@mcp.tool()
def wpbakery_set_status(client: str, post_id: int, status: str) -> str:
    """Change a page's status (publish a draft, unpublish, etc.). Busts caches.

    Args:
        client: SEO toolkit client slug.
        post_id: WordPress post/page ID.
        status: publish | draft | pending | private | future | trash
                (trash moves it to the WordPress Trash; recoverable).
    """
    return _wrap(t.set_status, client, post_id, status)


@mcp.tool()
def wpbakery_set_post_meta(
    client: str,
    post_id: int,
    key: str,
    value: str,
    is_json: bool = False,
) -> str:
    """Set a post meta value through WordPress (so caches invalidate). Use for SEO
    and indexing, e.g. Rank Math title/description/robots.

    For array values (like rank_math_robots), pass a JSON string and is_json=true:
      key="rank_math_robots", value='["noindex","nofollow"]', is_json=true

    Args:
        client: SEO toolkit client slug.
        post_id: WordPress post/page ID.
        key: Meta key (e.g. rank_math_title, rank_math_description, rank_math_robots).
        value: Meta value (string, or JSON string when is_json=true).
        is_json: Parse value as JSON (store an array/object) instead of a string.
    """
    return _wrap(t.set_post_meta, client, post_id, key, value, is_json)


@mcp.tool()
def wpbakery_replace_in_content(
    client: str,
    post_id: int,
    find: str,
    replace: str,
    expected: int | None = None,
) -> str:
    """Surgically replace a substring in a page's shortcode content without
    resending the whole page. Regenerates CSS + busts caches.

    Args:
        client: SEO toolkit client slug.
        post_id: WordPress post/page ID.
        find: Exact substring to find (must be present).
        replace: Replacement string.
        expected: If set, require exactly this many matches or abort (safety).
    """
    return _wrap(t.replace_in_content, client, post_id, find, replace, expected)


@mcp.tool()
def wpbakery_purge_cache(client: str, post_id: int) -> str:
    """Bust object + page caches for a page (object cache, WP Rocket, LiteSpeed,
    W3TC, Super Cache, Breeze/Varnish). Writes already auto-purge; use this if a
    change still looks stale.

    Args:
        client: SEO toolkit client slug.
        post_id: WordPress post/page ID.
    """
    return _wrap(t.purge_cache, client, post_id)


def main() -> None:
    mcp.run()


if __name__ == "__main__":
    main()
