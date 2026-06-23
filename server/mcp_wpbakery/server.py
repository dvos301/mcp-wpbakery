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
Build and edit pages on WordPress sites running the WPBakery Page Builder.
The whole point is NATIVE, editable elements. Follow these rules:

1. NATIVE ELEMENTS ONLY. Build from real WPBakery elements (vc_row, vc_column,
   vc_tta_accordion, vc_custom_heading, vc_single_image, ...). NEVER lay out
   content with a vc_raw_html block — it is one opaque, uneditable element.

2. PREFER THE THEME'S OWN ELEMENTS. When a theme ships its own element, use it
   over the WPBakery core twin — many themes only render their own. On Impreza /
   us-core: use `us_btn` (NOT `vc_btn` — it renders as literal "[vc_btn]" text),
   `us_iconbox`, `us_image`. Check wpbakery_list_elements for `us_*`/theme tags.

3. DISCOVER FIRST. wpbakery_list_elements (summary) → wpbakery_element_schema
   for the exact params of each element you'll use.

4. STYLE VIA PAGE CSS, NOT HTML, NOT design-options. Give elements an el_class
   and style them with wpbakery_set_page_css / wpbakery_append_page_css (or
   update_page's page_css). Do NOT use the WPBakery `css=` design-options
   attribute for section backgrounds/padding — themes like Impreza ignore it.
   Page CSS now writes ALL theme CSS keys and busts caches automatically.

5. THEMES RE-RENDER ELEMENTS. Impreza renders accordions as `.w-tabs` /
   `.w-tabs-section-header`, not `.vc_tta-*`. Use wpbakery_render_preview to see
   the REAL rendered HTML and class names; target those with !important.

6. ALWAYS PREVIEW AFTER WRITING. content.rendered from a plain read lies — call
   wpbakery_render_preview(post_id). It returns the true front-end HTML, a list
   of `unrendered_shortcodes` (elements the theme dropped — fix those), and a
   public `preview_url` you can screenshot (drafts included).

7. SAFE WRITES. wpbakery_validate before writing. Build on a DRAFT
   (wpbakery_create_page status=draft) → render_preview → fix → publish with
   wpbakery_set_status. Every write saves a revision. Set noindex / SEO meta via
   wpbakery_set_post_meta (e.g. rank_math_robots = ["noindex","nofollow"]).

8. ITERATE CHEAPLY. Don't resend the whole page to change one thing — use
   wpbakery_append_page_css for CSS and wpbakery_replace_in_content for surgical
   content edits.

Typical flow: ping -> list_elements/element_schema -> create_page(draft) ->
build native shortcodes -> validate -> update_page -> set_page_css ->
render_preview (screenshot preview_url, check unrendered_shortcodes) -> fix ->
set_status(publish).
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
        status: publish | draft | pending | private | future.
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
