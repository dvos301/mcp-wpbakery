"""Client config resolution — standalone, no external toolkit required.

A "client" is one WordPress site. Config is resolved in this order:

  1. <WPBAKERY_CONFIG_DIR>/<slug>.json   (env override, if set)
  2. <repo>/clients/<slug>.json          (the bundled clients dir)
  3. ~/seo_toolkit/clients/<slug>.json   (back-compat for the original author)
  4. single-client env mode              (WPBAKERY_BASE_URL + creds)

A client JSON looks like (REST transport):

    {
      "base_url": "https://example.com",
      "wp_transport": "rest",
      "wp_rest_user": "wp-username",
      "wp_rest_app_password": "xxxx xxxx xxxx xxxx xxxx xxxx"
    }

Secrets can instead come from env (see _rest.py): WPBAKERY_<SLUG>_USER /
WPBAKERY_<SLUG>_APP_PW, so the JSON need not contain the password.
"""

from __future__ import annotations

import json
import os
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]  # mcp-wpbakery/


def _candidate_files(slug: str) -> list[Path]:
    files = []
    env_dir = os.environ.get("WPBAKERY_CONFIG_DIR")
    if env_dir:
        files.append(Path(env_dir).expanduser() / f"{slug}.json")
    files.append(REPO_ROOT / "clients" / f"{slug}.json")
    toolkit = os.environ.get("SEO_TOOLKIT_DIR", str(Path.home() / "seo_toolkit"))
    files.append(Path(toolkit) / "clients" / f"{slug}.json")
    return files


def _from_env(slug: str) -> dict | None:
    base = os.environ.get("WPBAKERY_BASE_URL")
    if not base:
        return None
    return {
        "_name": slug,
        "base_url": base,
        "wp_transport": os.environ.get("WPBAKERY_TRANSPORT", "rest"),
        "wp_rest_user": os.environ.get("WPBAKERY_REST_USER"),
        "wp_rest_app_password": os.environ.get("WPBAKERY_REST_APP_PW"),
        "server_ip": os.environ.get("WPBAKERY_SERVER_IP"),
        "ssh_user": os.environ.get("WPBAKERY_SSH_USER"),
        "wp_app_slug": os.environ.get("WPBAKERY_WP_APP_SLUG"),
        "ssh_key": os.environ.get("WPBAKERY_SSH_KEY"),
    }


def load(slug: str) -> dict:
    for path in _candidate_files(slug):
        if path.exists():
            cfg = json.loads(path.read_text())
            cfg["_name"] = slug
            return cfg
    env_cfg = _from_env(slug)
    if env_cfg:
        return env_cfg
    searched = "\n  ".join(str(p) for p in _candidate_files(slug))
    raise FileNotFoundError(
        f"No config for client '{slug}'. Looked in:\n  {searched}\n"
        "Create clients/<slug>.json (see clients/example.json) or set "
        "WPBAKERY_BASE_URL + WPBAKERY_REST_USER + WPBAKERY_REST_APP_PW."
    )


def available() -> list[str]:
    """Slugs discoverable in the active config dirs (for diagnostics)."""
    slugs = set()
    dirs = []
    if os.environ.get("WPBAKERY_CONFIG_DIR"):
        dirs.append(Path(os.environ["WPBAKERY_CONFIG_DIR"]).expanduser())
    dirs.append(REPO_ROOT / "clients")
    for d in dirs:
        if d.is_dir():
            for p in d.glob("*.json"):
                if p.stem != "example":
                    slugs.add(p.stem)
    return sorted(slugs)
