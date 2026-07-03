"""Package the plugin as mcp-wpbakery.zip for manual upload via WP admin.

    python pack.py            # writes dist/mcp-wpbakery.zip

Upload it in WordPress: Plugins → Add New → Upload Plugin → Activate.
"""

from __future__ import annotations

import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PLUGIN_DIR = ROOT / "wp-plugin"
PLUGIN_SLUG = "mcp-wpbakery"


def main() -> None:
    dist = ROOT / "dist"
    dist.mkdir(exist_ok=True)
    out = dist / f"{PLUGIN_SLUG}.zip"
    if out.exists():
        out.unlink()

    junk = {".DS_Store", "Thumbs.db"}
    files = sorted(
        p
        for p in PLUGIN_DIR.rglob("*")
        if p.is_file()
        and p.name not in junk
        and not p.name.endswith((".pyc", ".swp", ".orig"))
        and "__pycache__" not in p.parts
    )
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for f in files:
            # Store under a top-level folder named after the plugin slug,
            # which is what WordPress expects inside the zip.
            arc = Path(PLUGIN_SLUG) / f.relative_to(PLUGIN_DIR)
            z.write(f, arcname=str(arc))
        # Ship the build rules with the plugin so the remote MCP endpoint can
        # serve them as session instructions (same contract the local hub
        # injects). The repo-root file stays the single source of truth.
        rules = ROOT / "WPBAKERY_BUILD_RULES.md"
        if rules.exists():
            z.write(rules, arcname=str(Path(PLUGIN_SLUG) / rules.name))

    print(f"✓ wrote {out}  ({out.stat().st_size} bytes, {len(files)} files)")
    print("  Upload: WP admin → Plugins → Add New → Upload Plugin → Activate")


if __name__ == "__main__":
    main()
