# MCP WPBakery

An MCP server that lets an AI agent (Claude Code) read a WordPress site's live
**WPBakery (js_composer) element registry** (`vc_map`) and build / edit
**native, fully-editable WPBakery elements** on pages — not raw-HTML hacks.

Open any page it writes in the WPBakery editor and every row, column, and
element is editable exactly as if a human dragged it in.

---

## 📐 Build rules (mandatory — auto-loaded into every agent session)

This repo ships **[`WPBAKERY_BUILD_RULES.md`](WPBAKERY_BUILD_RULES.md)** — the
non-negotiable contract for *how* to build pages: granular native elements, the
forbidden "cram a whole section into one text block" anti-pattern, the
element-selection map, attribute value encoding, a self-audit gate, and
custom-element authoring guidance.

**You don't have to save or wire it up anywhere.** The MCP server reads this
file at startup and appends it to the server's MCP `instructions`, so it is
injected into **every** Claude Code session that connects to the `wpbakery_*`
tools (see `server/mcp_wpbakery/server.py`, which loads the repo-root
`WPBAKERY_BUILD_RULES.md`). Edit the file → it takes effect on the next session.
Keep it in the repo root so the server can find it. The agent guide
[`CLAUDE.md`](CLAUDE.md) also points to it.

> **Agents:** read `WPBAKERY_BUILD_RULES.md` before building or editing any page,
> and follow it exactly. A page that renders but collapses content into one
> opaque block is a failure — every distinct piece must be its own editable element.

---

## ⚠️ Installing the WordPress plugin — use the Release, not "Download ZIP"

The WordPress plugin lives in **[Releases](https://github.com/dvos301/mcp-wpbakery/releases/latest)**.
Download **`mcp-wpbakery.zip`** from the latest release and install it via
WP admin → Plugins → Add New → Upload Plugin → Activate.

> **Do NOT** use the green **Code → Download ZIP** button for the WordPress
> install. That downloads the whole source repo (`server/`, `clients/`,
> `wp-plugin/`, …) — WordPress will reject it with *"No valid plugins were
> found."* Use it only for cloning/development.

| You want to… | Get this |
|---|---|
| Install the plugin on a WP site | **Releases → `mcp-wpbakery.zip`** |
| Set up the MCP server / develop | Clone the repo (or green Code → Download ZIP) + `./install.sh` |

---

## How it works

```
Claude Code ──MCP(stdio)──▶ server (Python) ──SSH + WP-CLI──▶ companion WP plugin ──▶ vc_map + post content
```

Two parts:

| Part | What it does |
|------|--------------|
| **`wp-plugin/`** — *MCP WPBakery Bridge* | A WordPress plugin (the engine). Reads the live `vc_map` registry, parses/serializes WPBakery shortcodes, validates against the registry, and regenerates WPBakery's Design-Options custom CSS after edits. Exposed via WP-CLI (`wp mcp-wpbakery …`) and REST (`/wp-json/mcp-wpbakery/v1/…`). |
| **`server/`** — Python MCP server | Talks to the plugin over SSH + WP-CLI (reusing your Cloudways key and `~/seo_toolkit/clients/*.json` configs) and exposes clean MCP tools. |

### Why a companion plugin is required

`vc_map` only exists inside PHP/WordPress at runtime, and producing *editable*
elements means emitting shortcodes that match each element's registered schema
**and** keeping the `_wpb_shortcodes_custom_css` post meta in sync. Both must run
in WP context — a generic WordPress REST tool can't do either.

## The gotchas it handles for you

- **Design Options CSS** lives in the `_wpb_shortcodes_custom_css` post meta, not
  the shortcode. After every write the plugin calls WPBakery's own
  `vc_base()->save_post_custom_css()` so styling actually renders.
- **Native editability** — elements are validated against `vc_map`, so the editor
  recognises them as real elements (correct params, container nesting,
  self-closing vs enclosing tags).
- **Backups** — each write saves a WordPress revision first.
- **Safe transport** — structured/large args are base64-encoded over SSH; the
  plugin replies with a single sentinel-wrapped JSON line.

## MCP tools

| Tool | Purpose |
|------|---------|
| `wpbakery_ping` | Confirm connectivity + WPBakery version. **Call first.** |
| `wpbakery_list_elements` | Full `vc_map` registry (core + theme + add-ons). |
| `wpbakery_element_schema` | One element's full param schema. |
| `wpbakery_list_pages` | Pages/posts, flagged for WPBakery use. |
| `wpbakery_get_page` | Raw content + parsed tree + custom CSS. |
| `wpbakery_get_structure` | Just the parsed shortcode tree. |
| `wpbakery_build_element` | Build one validated, editable element (no DB write). |
| `wpbakery_validate` | Validate shortcode content against `vc_map`. |
| `wpbakery_update_page` | Write content (revision backup + CSS regen). |

## Transports

The server auto-selects per client:

- **REST** — HTTP + a WordPress Application Password. Use when you don't have
  (or don't want) SSH. Requires manual one-time plugin install.
- **SSH** — SSH + WP-CLI via the Cloudways key. Fully automated install.

Force one with `cfg["wp_transport"] = "rest"|"ssh"` or env `WPBAKERY_TRANSPORT`.

## Setup — REST (no SSH)

### 1. Build and install the plugin

```bash
cd server
pip install -r requirements.txt
python pack.py            # -> dist/mcp-wpbakery.zip
```

In WordPress: **Plugins → Add New → Upload Plugin →** upload `dist/mcp-wpbakery.zip`
**→ Activate**.

### 2. Create an Application Password

WP admin → **Users → Profile → Application Passwords** → add one (for an
admin/editor). Copy the generated password.

### 3. Give the server the credentials

Per-client env vars (slug uppercased), e.g. for `vista`:

```bash
export WPBAKERY_VISTA_USER="your-wp-username"
export WPBAKERY_VISTA_APP_PW="xxxx xxxx xxxx xxxx xxxx xxxx"   # spaces fine
```

(Or set `wp_rest_user` / `wp_rest_app_password` in the client JSON. Env is
preferred for secrets — e.g. add the exports to `~/.superseo-secrets/env.sh`.)

The REST base URL comes from the client config's `base_url`.

## Setup — SSH (Cloudways key)

```bash
cd server
pip install -r requirements.txt
python deploy.py <client-slug> --check     # verify SSH + WP-CLI + WP root
python deploy.py <client-slug>             # upload + activate the plugin
```

Needs `server_ip`, `ssh_user`, `wp_app_slug` in the client JSON, and the
`cloudways_ed25519` public key authorized on that server.

## Register the MCP server with Claude Code

```bash
claude mcp add wpbakery -- python -m mcp_wpbakery.server
```

…run from `server/` (or set `PYTHONPATH` to it). Optional env vars:

- `SEO_TOOLKIT_DIR` — defaults to `~/seo_toolkit`
- `WPBAKERY_SSH_KEY` — defaults to `~/.ssh/cloudways_ed25519`
- `WPBAKERY_<SLUG>_USER` / `WPBAKERY_<SLUG>_APP_PW` — REST credentials

### 3. Use it

```
You: Add a hero row with a heading and a "Get a quote" button to page 42 on lwa.
Claude: wpbakery_ping(lwa) → wpbakery_element_schema(lwa, vc_btn) →
        wpbakery_build_element(...) → wpbakery_validate(...) → wpbakery_update_page(lwa, 42, ...)
```

## Typical agent workflow

1. `wpbakery_ping` — confirm the site + WPBakery.
2. `wpbakery_list_elements` — discover available elements (incl. theme/add-on).
3. `wpbakery_element_schema` — get exact params for the ones you'll use.
4. `wpbakery_get_page` — read current structure (to insert/modify in place).
5. `wpbakery_build_element` — assemble each element; check warnings.
6. `wpbakery_validate` — sanity-check the full content.
7. `wpbakery_update_page` — write it (auto revision + CSS regen).

## Removing the plugin

```bash
python deploy.py <client-slug> --remove
```

## Status

v0.1.0 — working and **verified end-to-end** against a real WPBakery **8.7.1** +
Impreza/us-core site (locally, via the `vista-test` Local install):

- ✅ Reads live `vc_map` — 80 elements incl. theme/add-on custom ones
- ✅ Element schemas (params, types, dropdown options, defaults)
- ✅ `build_element` emits validated, native shortcodes (0 warnings)
- ✅ parse → serialize round-trip is stable
- ✅ `validate` catches unregistered/typo'd elements
- ✅ `update_page` writes content, regenerates `_wpb_shortcodes_custom_css`,
  sets `_wpb_vc_js_status=true`, and creates a revision backup

Pending: live run against vista production once the plugin is installed and an
Application Password is provided (REST transport).
