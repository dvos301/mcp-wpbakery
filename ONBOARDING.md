# Onboarding — MCP WPBakery

This lets Claude read a WordPress site's **WPBakery (js_composer)** element
registry and build **native, editable** WPBakery elements — rows, columns,
accordions, buttons, headings, the site's own custom elements — that open and
edit normally in the WPBakery editor. Not raw-HTML dumps.

There are two parts:

- **A WordPress plugin** ("MCP WPBakery Bridge") installed on each site — the
  engine that reads `vc_map` and writes content/CSS.
- **A Python MCP server** on your machine that Claude Code talks to.

---

## Setup (≈10 minutes)

### 1. Get the code + run the installer

```bash
git clone https://github.com/dvos301/mcp-wpbakery.git
cd mcp-wpbakery
./install.sh
```

`install.sh` installs Python deps, builds `dist/mcp-wpbakery.zip`, and registers
the `wpbakery` MCP server with Claude Code.

### 2. Install the plugin on the WordPress site (once per site)

Download the plugin zip from the latest release:
**https://github.com/dvos301/mcp-wpbakery/releases/latest** (file: `mcp-wpbakery.zip`)
— or build it locally with `./install.sh` (creates `dist/mcp-wpbakery.zip`).

Then in WP admin → **Plugins → Add New → Upload Plugin** → choose the zip →
**Activate**.

(If you have SSH/WP-CLI to the site instead: `cd server && python3 deploy.py <slug>`.)

After activating you'll see an **MCP WPBakery** item in the WP admin sidebar.

### 3. Generate the connection prompt (one click)

WP admin → **MCP WPBakery** → set a **client slug** (a short label, e.g. `vista`)
→ **Generate Application Password & prompt**.

The page creates an Application Password and gives you a **ready-to-paste prompt**
containing everything the agent needs — site URL, username, password, slug, and
endpoint. Click **Copy prompt**.

### 4. Paste it to Claude

Paste that prompt into Claude Code. If you've done Step 1 (the MCP server is
installed and Claude Code has been restarted), the agent writes
`clients/<slug>.json` and runs `wpbakery_ping` to confirm — done.

If the MCP server **isn't** set up on this computer yet, the prompt tells the
agent to install it first (`git clone … && ./install.sh`), after which you
restart Claude Code and paste the prompt again. So pasting it is safe even from a
fresh machine — it self-bootstraps. (The connection to the site is the WordPress
REST API + the Application Password; the MCP server is just the local bridge.)

> Manual alternative: `cp clients/example.json clients/<slug>.json`, fill in
> `base_url` / `wp_rest_user` / `wp_rest_app_password`, then
> `python3 -c "from mcp_wpbakery import transport as t; print(t.ping('<slug>'))"`.
> (Secrets stay local — `clients/*.json` is git-ignored.)

Restart Claude Code after first install so the `wpbakery_*` tools load.

---

## How to use it (read this — it's the difference between good and bad output)

The tools let an agent build anything, but **quality depends on building the
right way**. The server ships these rules to the agent, and they matter:

1. **Native elements only.** Build from real WPBakery elements. **Never** use a
   Raw HTML (`vc_raw_html`) block to lay out content — it becomes one opaque
   block you can't edit in the builder. (This is the #1 mistake; it defeats the
   whole point.)
2. **Discover first.** The agent lists the site's elements and reads their
   schemas before building — including the site's custom/theme elements.
3. **Style via Page CSS.** To skin native elements, the agent gives them an
   `el_class` and writes the CSS into the page's **Custom CSS** (Page Settings),
   not into an HTML block. Everything stays editable.
4. **Draft → preview → publish.** Work happens on a draft first (every write
   saves a revision). The agent calls `wpbakery_render_preview` to get a public
   preview URL, screenshots it with `python screenshot.py "<url>" shot.png`, and
   checks it actually looks right before publishing.

### Example prompts

- "On `vista`, add an FAQ to a **draft** page using a native accordion, styled
  like shadcn (light, rounded card, hairline dividers, chevron). Don't publish."
- "Read page 6946 on `vista` and add a hero row with a heading and a
  'Get a quote' button using native elements."
- "Restyle the accordion on draft 11280 — more padding, darker dividers." (the
  agent edits Page CSS instantly, no re-upload)

### What "good" looks like

The canonical flow:
`create_page` (draft) → `list_elements` / `element_schema` → build native
elements → `validate` → `update_page` → `set_page_css` to skin (target the
theme's real classes with `!important`) → `render_preview` →
`screenshot.py "<preview_url>"` and look at it → fix → `set_status` publish.

---

## Tools

| Tool | Purpose |
|------|---------|
| `wpbakery_ping` | Confirm connectivity + WPBakery version. Call first. |
| `wpbakery_list_elements` | The site's full `vc_map` (core + theme + add-ons). |
| `wpbakery_element_schema` | One element's exact params/options. |
| `wpbakery_list_pages` | Pages/posts, flagged for WPBakery use. |
| `wpbakery_get_page` | Raw content + parsed tree + custom CSS. |
| `wpbakery_build_element` | Build one validated native element (no write). |
| `wpbakery_validate` | Validate shortcode content against `vc_map`. |
| `wpbakery_update_page` | Write content (+ optional page CSS); revision + CSS regen. |
| `wpbakery_set_page_css` | Set/iterate a page's custom CSS without touching content. |

---

## Troubleshooting

- **`ping` 401/403:** Application Password wrong, or the server strips the
  `Authorization` header (Apache/Cloudways). Fix: add to `.htaccess`
  `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`, or use the SSH transport.
- **`vc_active: false`:** activate WPBakery Page Builder on the site.
- **Tools don't appear in Claude Code:** restart it; check `claude mcp list`
  shows `wpbakery ✔ Connected`.
- **Updated the plugin:** re-upload the new `dist/mcp-wpbakery.zip` (choose
  "Replace current with uploaded"); `wpbakery_ping` shows the new version.

## Updating the plugin

After changing `wp-plugin/`, run `cd server && python3 pack.py`, bump the version
in `wp-plugin/mcp-wpbakery.php`, and re-upload the zip (or `deploy.py` over SSH).
