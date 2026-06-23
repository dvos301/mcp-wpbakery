#!/usr/bin/env bash
#
# One-command setup for the MCP WPBakery bridge.
#
#   ./install.sh
#
# Does the machine-level setup:
#   1. installs Python deps
#   2. builds the plugin zip (dist/mcp-wpbakery.zip)
#   3. registers the MCP server with Claude Code (user scope)
#
# Per-site steps are still needed once (see the printed NEXT STEPS):
#   - upload dist/mcp-wpbakery.zip to the WP site and activate
#   - create an Application Password and add a clients/<slug>.json
#
set -euo pipefail
cd "$(dirname "$0")"
ROOT="$(pwd)"

echo "==> Installing Python dependencies"
python3 -m pip install -r server/requirements.txt

echo "==> Building plugin zip"
( cd server && python3 pack.py )

echo "==> Registering MCP server with Claude Code (user scope)"
if command -v claude >/dev/null 2>&1; then
  claude mcp remove wpbakery -s user >/dev/null 2>&1 || true
  claude mcp add wpbakery -s user \
    --env PYTHONPATH="$ROOT/server" \
    --env WPBAKERY_CONFIG_DIR="$ROOT/clients" \
    -- python3 -m mcp_wpbakery.server
else
  echo "  ! 'claude' CLI not found — register manually:"
  echo "    claude mcp add wpbakery -s user --env PYTHONPATH=$ROOT/server --env WPBAKERY_CONFIG_DIR=$ROOT/clients -- python3 -m mcp_wpbakery.server"
fi

cat <<EOF

==> NEXT STEPS (once per WordPress site)
  1. Install the plugin:
       WP admin -> Plugins -> Add New -> Upload Plugin
       -> $ROOT/dist/mcp-wpbakery.zip -> Activate
     (or, if you have SSH/WP-CLI: cd server && python3 deploy.py <slug>)

  2. Create an Application Password:
       WP admin -> Users -> Profile -> Application Passwords

  3. Add a client config:
       cp clients/example.json clients/<slug>.json
       # fill in base_url, wp_rest_user, wp_rest_app_password

  4. Test:
       cd server && python3 -c "from mcp_wpbakery import transport as t; print(t.ping('<slug>'))"

Done. Read ONBOARDING.md for how to actually build elements (native, not Raw HTML).
EOF
