"""SSH + WP-CLI backend for the MCP WPBakery bridge.

Runs `wp mcp-wpbakery <subcommand>` on the remote site via the Cloudways key.
All functions take a resolved config dict (see transport.get_config).
The plugin replies with a sentinel-wrapped JSON envelope:

    <<<MCPWPB>>>{"ok":true,"data":...}<<<END>>>
"""

from __future__ import annotations

import base64
import json
import os
import re
import subprocess
from pathlib import Path

from .errors import RemoteError, TransportError

DEFAULT_SSH_KEY = os.environ.get(
    "WPBAKERY_SSH_KEY", str(Path.home() / ".ssh" / "cloudways_ed25519")
)

_ENVELOPE = re.compile(r"<<<MCPWPB>>>(.*?)<<<END>>>", re.DOTALL)

# Cache the WP root that actually worked, per server.
_resolved_path: dict[str, str] = {}


def _expand(path: str) -> str:
    return os.path.expanduser(os.path.expandvars(path)) if path else path


def wp_path_candidates(cfg: dict) -> list[str]:
    """Possible WordPress roots on a Cloudways box, most likely first."""
    if cfg.get("wp_path"):
        return [cfg["wp_path"]]
    user = cfg["ssh_user"]
    slug = cfg["wp_app_slug"]
    return [
        f"/home/master/applications/{slug}/public_html",
        f"/home/{user}/applications/{slug}/public_html",
        f"~/applications/{slug}/public_html",
        "~/public_html",
        "public_html",
    ]


def ssh_base(cfg: dict) -> list[str]:
    key = _expand(cfg.get("ssh_key") or DEFAULT_SSH_KEY)
    return [
        "ssh",
        "-i", key,
        "-o", "StrictHostKeyChecking=accept-new",
        "-o", "BatchMode=yes",
        "-o", "ConnectTimeout=15",
        f"{cfg['ssh_user']}@{cfg['server_ip']}",
    ]


def run_ssh(cfg: dict, remote_cmd: str, timeout: int = 90) -> subprocess.CompletedProcess:
    cmd = ssh_base(cfg) + [remote_cmd]
    try:
        return subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
    except subprocess.TimeoutExpired as e:
        raise TransportError(f"SSH command timed out after {timeout}s") from e
    except FileNotFoundError as e:
        raise TransportError("ssh binary not found") from e


def _require_fields(cfg: dict) -> None:
    missing = [k for k in ("server_ip", "ssh_user", "wp_app_slug") if not cfg.get(k)]
    if missing:
        raise TransportError(
            f"Client '{cfg.get('_name')}' is missing SSH fields {missing}. "
            "SSH transport needs server_ip, ssh_user and wp_app_slug."
        )


def _invoke(cfg: dict, wp_args: str, timeout: int = 90):
    _require_fields(cfg)
    key = cfg.get("server_ip", "default")
    candidates = (
        [_resolved_path[key]] if key in _resolved_path else wp_path_candidates(cfg)
    )

    last = ""
    for path in candidates:
        # cd into the root first: some wp-config.php files use a relative
        # require('wp-salt.php') that only resolves from inside the WP root.
        remote = f"cd {path} 2>/dev/null && wp --path={path} {wp_args}"
        proc = run_ssh(cfg, remote, timeout=timeout)
        combined = (proc.stdout or "") + "\n" + (proc.stderr or "")
        m = _ENVELOPE.search(combined)
        if m:
            _resolved_path[key] = path
            payload = json.loads(m.group(1))
            if not payload.get("ok"):
                raise RemoteError(payload.get("error", "unknown error"))
            return payload.get("data")
        last = combined.strip()[-800:]

    raise TransportError(
        "No response from `wp mcp-wpbakery` over SSH. Is WP-CLI installed and the "
        f"MCP WPBakery Bridge plugin active?\nLast output:\n{last}"
    )


def _b64(s: str) -> str:
    return base64.b64encode(s.encode("utf-8")).decode("ascii")


# ---- backend API (cfg-first) ----------------------------------------------

def ping(cfg):
    return _invoke(cfg, "ping")


def list_elements(cfg):
    return _invoke(cfg, "elements", timeout=120)


def element(cfg, tag):
    return _invoke(cfg, f"element {re.sub(r'[^a-zA-Z0-9_-]', '', tag)}")


def list_posts(cfg, post_type=None, search=None, limit=None):
    args = "list"
    if post_type:
        args += f" --post_type={re.sub(r'[^a-zA-Z0-9_,-]', '', post_type)}"
    if limit:
        args += f" --limit={int(limit)}"
    if search:
        args += f" --search={_b64(search)}"
    return _invoke(cfg, args)


def get_post(cfg, post_id):
    return _invoke(cfg, f"get {int(post_id)}")


def structure(cfg, post_id):
    return _invoke(cfg, f"structure {int(post_id)}")


def build_element(cfg, tag, atts=None, inner=""):
    args = f"build --tag={re.sub(r'[^a-zA-Z0-9_-]', '', tag)}"
    if atts:
        args += f" --atts={_b64(json.dumps(atts))}"
    if inner:
        args += f" --content={_b64(inner)}"
    return _invoke(cfg, args)


def validate(cfg, content):
    return _invoke(cfg, f"validate --content={_b64(content)}")


def update_post(cfg, post_id, content, skip_validate=False, page_css=None):
    args = f"update {int(post_id)} --content={_b64(content)}"
    if skip_validate:
        args += " --no-validate"
    if page_css is not None:
        args += f" --page_css={_b64(page_css)}"
    return _invoke(cfg, args, timeout=120)


def set_page_css(cfg, post_id, css):
    return _invoke(cfg, f"set-page-css {int(post_id)} --css={_b64(css)}")


def append_page_css(cfg, post_id, css):
    return _invoke(cfg, f"append-page-css {int(post_id)} --css={_b64(css)}")


def render_preview(cfg, post_id):
    return _invoke(cfg, f"render-preview {int(post_id)}", timeout=120)


def create_page(cfg, title, slug="", status="draft"):
    args = f"create --title={_b64(title)} --status={re.sub(r'[^a-z]', '', status)}"
    if slug:
        args += f" --slug={re.sub(r'[^a-z0-9-]', '', slug.lower())}"
    return _invoke(cfg, args)


def set_status(cfg, post_id, status):
    return _invoke(cfg, f"set-status {int(post_id)} --status={re.sub(r'[^a-z]', '', status)}")


def set_post_meta(cfg, post_id, key, value, is_json=False):
    if not isinstance(value, str):
        value = json.dumps(value)
        is_json = True
    args = f"set-meta {int(post_id)} --key={re.sub(r'[^a-zA-Z0-9_-]', '', key)} --value={_b64(value)}"
    if is_json:
        args += " --json"
    return _invoke(cfg, args)


def replace_in_content(cfg, post_id, find, replace, expected=None):
    args = f"replace {int(post_id)} --find={_b64(find)} --replace={_b64(replace)}"
    if expected is not None:
        args += f" --expected={int(expected)}"
    return _invoke(cfg, args, timeout=120)


def purge_cache(cfg, post_id):
    return _invoke(cfg, f"purge {int(post_id)}")
