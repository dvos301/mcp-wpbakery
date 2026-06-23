"""Deploy the MCP WPBakery Bridge plugin to a client's WordPress site over SSH.

Usage:
    python deploy.py <client-slug>            # install + activate
    python deploy.py <client-slug> --check    # just resolve path + wp version
    python deploy.py <client-slug> --remove   # deactivate + delete plugin

For sites without SSH, install the plugin zip manually (see `python pack.py`)
and use the REST transport instead — no deploy step needed.

This performs a WRITE to a live site (installs/activates a plugin) — run
deliberately.
"""

from __future__ import annotations

import subprocess
import sys
import tarfile
import tempfile
from pathlib import Path

from mcp_wpbakery import _ssh, transport

PLUGIN_DIR = Path(__file__).resolve().parent.parent / "wp-plugin"
PLUGIN_SLUG = "mcp-wpbakery"


def _wp(cfg, path, args):
    # cd first so relative requires in wp-config (e.g. wp-salt.php) resolve.
    return _ssh.run_ssh(cfg, f"cd {path} 2>/dev/null && wp --path={path} {args}")


def _resolve_path(cfg: dict) -> str:
    for path in _ssh.wp_path_candidates(cfg):
        ver = (_wp(cfg, path, "core version 2>/dev/null").stdout or "").strip()
        if ver and ver[0].isdigit():
            return path
    raise SystemExit(
        "Could not locate a working WordPress root via WP-CLI.\n"
        "Tried: " + ", ".join(_ssh.wp_path_candidates(cfg))
    )


def check(client: str) -> str:
    cfg = transport.get_config(client)
    path = _resolve_path(cfg)
    ver = _wp(cfg, path, "core version").stdout.strip()
    print(f"✓ SSH OK  {cfg['ssh_user']}@{cfg['server_ip']}")
    print(f"✓ WP root {path}")
    print(f"✓ WP-CLI  WordPress {ver}")
    vc = _wp(cfg, path, "plugin get js_composer --field=version 2>/dev/null").stdout.strip()
    print(f"  WPBakery (js_composer): {vc or 'not detected'}")
    return path


def deploy(client: str) -> None:
    cfg = transport.get_config(client)
    path = check(client)
    dest = f"{path}/wp-content/plugins"

    with tempfile.TemporaryDirectory() as tmp:
        tar_path = Path(tmp) / f"{PLUGIN_SLUG}.tar.gz"
        with tarfile.open(tar_path, "w:gz") as tar:
            tar.add(PLUGIN_DIR, arcname=PLUGIN_SLUG)

        remote_tmp = f"/tmp/{PLUGIN_SLUG}.tar.gz"
        scp = (
            ["scp"]
            + _ssh.ssh_base(cfg)[1:-1]  # -i key + options, drop "ssh" and host
            + [str(tar_path), f"{cfg['ssh_user']}@{cfg['server_ip']}:{remote_tmp}"]
        )
        print(f"→ uploading plugin to {remote_tmp} ...")
        r = subprocess.run(scp, capture_output=True, text=True)
        if r.returncode != 0:
            raise SystemExit(f"scp failed:\n{r.stderr}")

    cmd = (
        f"mkdir -p {dest} && tar -xzf {remote_tmp} -C {dest} && rm -f {remote_tmp} && "
        f"cd {path} && wp --path={path} plugin activate {PLUGIN_SLUG}"
    )
    print("→ extracting + activating ...")
    r = _ssh.run_ssh(cfg, cmd)
    print(r.stdout.strip())
    if r.stderr.strip():
        print(r.stderr.strip(), file=sys.stderr)

    print("\n→ verifying via plugin ping ...")
    print(transport.ping(client))


def remove(client: str) -> None:
    cfg = transport.get_config(client)
    path = _resolve_path(cfg)
    r = _wp(cfg, path, f"plugin deactivate {PLUGIN_SLUG} && wp --path={path} plugin delete {PLUGIN_SLUG}")
    print(r.stdout.strip() or r.stderr.strip())


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(__doc__)
        raise SystemExit(1)
    slug = sys.argv[1]
    flag = sys.argv[2] if len(sys.argv) > 2 else ""
    if flag == "--check":
        check(slug)
    elif flag == "--remove":
        remove(slug)
    else:
        deploy(slug)
