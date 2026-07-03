# MCP WPBakery

[![CI](https://github.com/dvos301/mcp-wpbakery/actions/workflows/ci.yml/badge.svg)](https://github.com/dvos301/mcp-wpbakery/actions/workflows/ci.yml)

Lets an AI agent (Claude Code) read a WordPress site's live **WPBakery
(js_composer) element registry** (`vc_map`) and build / edit **native,
fully-editable WPBakery elements** on pages — not raw-HTML hacks.

Open any page it writes in the WPBakery editor and every row, column, and
element is editable exactly as if a human dragged it in.

---

## 🚀 Quick start (v0.7.0+): connect directly, no local install

The WordPress plugin **is itself a remote MCP server**. Three steps:

1. **Install the plugin** — download
   [`mcp-wpbakery.zip`](https://github.com/dvos301/mcp-wpbakery/raw/main/dist/mcp-wpbakery.zip)
   (always current on `main`; also attached to each
   [Release](https://github.com/dvos301/mcp-wpbakery/releases/latest)) and
   upload it via **WP admin → Plugins → Add New → Upload Plugin → Activate**.
   Updating? WordPress offers *"Replace current with uploaded"* — tokens and
   settings survive.
2. **Generate a token** — wp-admin → **MCP WPBakery → Option A** → *Generate
   token* (admin + HTTPS required; shown once).
3. **Run the command it gives you** on the computer where Claude Code runs:

   ```bash
   claude mcp add --transport http wpbakery-<site> \
     https://<site>/wp-json/mcp-wpbakery/v1/mcp \
     --header "Authorization: Bearer wpbmcp_..."
   ```

Next Claude Code session, all **36 `wpbakery_*` tools** are available and the
[build rules](WPBAKERY_BUILD_RULES.md) are auto-injected as session
instructions. No Python, no `install.sh`, no Application Password.

**Security:** tokens are SHA-256-hashed at rest and shown once, issuance is
HTTPS-only, calls are rate-limited (120/min/token) and audit-logged (the REST
proxy logs the actual method + route). Revoke any token on the same admin page.

---

## 📦 Which download do I need?

| You want to… | Get this |
|---|---|
| Install/update the plugin on a WP site | [`dist/mcp-wpbakery.zip` (direct)](https://github.com/dvos301/mcp-wpbakery/raw/main/dist/mcp-wpbakery.zip) or the [latest Release](https://github.com/dvos301/mcp-wpbakery/releases/latest) |
| Run the local hub / develop | `git clone https://github.com/dvos301/mcp-wpbakery` + `./install.sh` |

> **Do NOT** upload GitHub's green **Code → Download ZIP** to WordPress — that
> is the whole source repo (`server/`, `clients/`, `wp-plugin/`, …) and
> WordPress rejects it with *"No valid plugins were found."*

---

## 🖥 Option B: the local hub (multi-site / SSH)

The original architecture still works unchanged and is the right choice when
you drive **many sites from one config folder**, or a site is only reachable
over **SSH + WP-CLI**:

```
Claude Code ─MCP(stdio)→ Python hub ─REST (app password) or SSH+WP-CLI→ WP plugin
```

Setup (full walkthrough in [ONBOARDING.md](ONBOARDING.md)):

```bash
git clone https://github.com/dvos301/mcp-wpbakery
cd mcp-wpbakery && ./install.sh        # installs deps + registers the MCP server
```

Per-site config in `clients/<slug>.json` (`base_url` + either
`wp_rest_user`/`wp_rest_app_password` or `server_ip`/`ssh_user`/`wp_app_slug`).
The admin page's **Option B** generates the app password + a paste-ready agent
prompt. Secrets can also live in env vars: `WPBAKERY_<SLUG>_USER` /
`WPBAKERY_<SLUG>_APP_PW`.

SSH deploys are automated: `python server/deploy.py <slug> --check`, then
`python server/deploy.py <slug>` (uses `~/.ssh/cloudways_ed25519`).

---

## 📐 Build rules (mandatory — auto-injected into every session)

[`WPBAKERY_BUILD_RULES.md`](WPBAKERY_BUILD_RULES.md) is the non-negotiable
contract for *how* to build pages: granular native elements, the forbidden
"cram a whole section into one text block" anti-pattern, the element-selection
map, attribute encoding, and a self-audit gate.

You never wire it up manually — **both** connection modes inject it:

- **Direct (Option A):** `pack.py` ships the file inside the plugin zip and the
  MCP `initialize` response serves it as session instructions.
- **Local hub (Option B):** `server/mcp_wpbakery/server.py` reads the repo-root
  file at startup and appends it to the server instructions.

Edit the repo-root file → rebuild the zip (Option A) or restart the session
(Option B). The agent guide [`CLAUDE.md`](CLAUDE.md) also points to it.

---

## 🧱 Element Studio — reusable custom elements that outlive this plugin (v0.8.0+)

Replaces element-builder plugins (Element Studio & co): the agent can author
**reusable custom WPBakery elements** — every field a real `vc_map` param, so
clients edit them in the builder like any native element.

Definitions are **not** stored in this plugin. They are written into a
standalone, auto-generated library plugin —
**`wp-content/plugins/custom-wpbakery-elements/`** — which owns the elements:

- Delete the MCP WPBakery Bridge → every custom element **keeps rendering and
  stays editable**. The library has zero dependency on the bridge.
- Each element is one JSON file (params + HTML template + scoped CSS) in the
  library's `elements/` folder — hand-editable, git-able, portable to other
  sites by copying the folder.
- Templates use `{{param}}` (escaped), `{{{param}}}` (rich HTML),
  `{{#if p}}…{{/if}}`, `{{#each group}}…{{/each}}` for repeatable rows, and
  `{{content}}` for enclosing elements. Element CSS prints once per page.
- Studio tools: `wpbakery_create_custom_element`, `wpbakery_update_custom_element`,
  `wpbakery_list_custom_elements`, `wpbakery_get_custom_element`,
  `wpbakery_delete_custom_element` (refuses to delete an element still used in
  content). Create/update/delete require the `install_plugins` capability.

## 🧰 MCP tools (36)

**Builder** (parity in both modes; hub tools take a `client` slug, remote tools don't — the connection identifies the site):

| Tool | Purpose |
|------|---------|
| `wpbakery_ping` | Connectivity + WP/WPBakery versions. **Call first.** |
| `wpbakery_list_elements` | The live `vc_map` registry (core + theme + add-ons). |
| `wpbakery_element_schema` | One element's full param schema. |
| `wpbakery_list_pages` | Pages/posts, flagged for WPBakery use. |
| `wpbakery_get_page` / `wpbakery_get_structure` | Raw content + parsed tree (+ CSS). |
| `wpbakery_build_element` | Build one validated, editable element (no DB write). |
| `wpbakery_validate` | Validate content against `vc_map` before writing. |
| `wpbakery_update_page` | The real write: validate → revision backup → write → CSS regen. |
| `wpbakery_set_page_css` / `wpbakery_append_page_css` | Page-scoped CSS iteration. |
| `wpbakery_render_preview` | True front-end render: tokenized preview URL + unrendered-shortcode detection. |
| `wpbakery_create_page` / `wpbakery_set_status` | Draft-first page lifecycle. |
| `wpbakery_set_post_meta` | Any post meta (Rank Math robots, etc.). |
| `wpbakery_replace_in_content` | Surgical find/replace with expected-count safety. |
| `wpbakery_purge_cache` | Object + page-cache busting for a page. |

**Site-wide** (remote endpoint, v0.7.0+):

| Tool | Purpose |
|------|---------|
| `wpbakery_find_pages` | Search by title/slug/content, or resolve a URL to its post. |
| `wpbakery_find_in_pages` | Sitewide text search, down to the shortcode element holding the match. |
| `wpbakery_page_links` | All links on a page with anchors (HTML + encoded vc link attributes). |
| `wpbakery_inbound_links` | Who links to a page, with anchor-frequency summary (menus included). |
| `wpbakery_link_map` | Whole-site internal link graph, top targets, orphan pages. |
| `wpbakery_site_status` | WP/PHP/theme/plugin versions, pending updates, debug + cache state. |
| `wpbakery_read_error_log` | Tail debug.log / PHP error log. |
| `wpbakery_get_seo_meta` / `wpbakery_set_seo_meta` | Rank Math / Yoast title, description, focus keyword. |
| `wpbakery_clone_page` | Duplicate a page (content + meta + page CSS) as a draft. |
| `wpbakery_rest_request` / `wpbakery_list_rest_routes` | Full core+plugin REST surface, in-process, permission-checked. |
| `wpbakery_create/update/get/delete/list_custom_element(s)` | Element Studio: author reusable custom elements into the standalone library plugin (see above). |

## Typical agent workflow

1. `wpbakery_ping` → 2. `wpbakery_list_elements` → 3. `wpbakery_element_schema`
→ 4. `wpbakery_get_page` → 5. `wpbakery_build_element` → 6. `wpbakery_validate`
→ 7. `wpbakery_update_page` (draft) → 8. `wpbakery_render_preview` (fix any
`unrendered_shortcodes`) → 9. `wpbakery_set_status(publish)`.

## Why a companion plugin is required

`vc_map` only exists inside PHP/WordPress at runtime, and producing *editable*
elements means emitting shortcodes that match each element's registered schema
**and** keeping `_wpb_shortcodes_custom_css` post meta in sync. Both must run in
WP context — a generic WordPress REST tool can't do either. The plugin also
saves a revision before every write and busts caches after.

## Development

```bash
python server/pack.py     # rebuild dist/mcp-wpbakery.zip (includes build rules)
```

CI (GitHub Actions) lints every plugin PHP file on PHP 7.2 and 8.2, compiles
the Python server, rebuilds the zip and verifies its contents on every push.

## Status

**v0.7.0** — hybrid: the plugin doubles as a remote MCP server (token auth,
rate limiting, audit log, 29 tools, build-rules injection) while the local hub
and REST/SSH transports keep working unchanged. Earlier milestones: verified
end-to-end against WPBakery 8.7.x + Impreza/us-core (live `vc_map` reads,
validated native builds, stable parse→serialize round-trips, revision-backed
writes with CSS regeneration).
