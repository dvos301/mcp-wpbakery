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
The whole point is to produce NATIVE, editable WPBakery elements. Follow these
rules or the output defeats the purpose:

1. NATIVE ELEMENTS ONLY. Build content from real WPBakery elements (vc_row,
   vc_column, vc_tta_accordion, vc_custom_heading, vc_btn, vc_single_image, ...).
   NEVER lay out content with a vc_raw_html block — in the builder that is one
   opaque element the user cannot edit, reorder, or restyle. A Raw HTML block is
   almost always the wrong answer.

2. DISCOVER FIRST. Call wpbakery_list_elements, then wpbakery_element_schema for
   the exact params of each element you'll use. Themes/add-ons register custom
   elements (e.g. Vista's vb_* elements) — prefer the site's real elements.

3. STYLE VIA PAGE CSS, NOT HTML. To skin native elements, give them an el_class
   and put the CSS in the page's Custom CSS with wpbakery_set_page_css (or the
   page_css arg of wpbakery_update_page). Never embed <style> through a Raw HTML
   element. Page CSS is the native WPBakery home for page-scoped styling and
   keeps every element editable.

4. THEMES RE-RENDER ELEMENTS. Many themes output their own markup (e.g. Impreza
   renders accordions as `.w-tabs` / `.w-tabs-section-header`, not `.vc_tta-*`).
   Fetch the live rendered HTML to find the real classes, target those, and use
   !important to win over theme CSS.

5. SAFE WRITES. Always wpbakery_validate before writing. Build on a DRAFT page,
   review it, then move to the live page. Every write auto-saves a revision.

Typical flow: ping -> list_elements/element_schema -> get_page (read current) ->
build native shortcodes -> validate -> update_page (draft) -> set_page_css to
skin -> review -> publish.
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


def main() -> None:
    mcp.run()


if __name__ == "__main__":
    main()
