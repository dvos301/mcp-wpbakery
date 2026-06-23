"""Transport dispatcher for the MCP WPBakery bridge.

Loads a client config from the SEO toolkit (~/seo_toolkit/clients/<slug>.json)
and routes each operation to the right backend:

  * REST  (_rest.py)  — HTTP + WordPress Application Password
  * SSH   (_ssh.py)   — SSH + WP-CLI (Cloudways key)

Selection per client:
  1. explicit:  cfg["wp_transport"] or env WPBAKERY_TRANSPORT  in {"rest","ssh"}
  2. inferred:  REST credentials present        -> rest
                else server_ip/ssh_user present  -> ssh
"""

from __future__ import annotations

import os
from functools import lru_cache

from . import _rest, _ssh, clientconfig
from .errors import RemoteError, TransportError  # re-exported for callers

__all__ = [
    "RemoteError",
    "TransportError",
    "get_config",
    "ping",
    "list_elements",
    "element",
    "list_posts",
    "get_post",
    "structure",
    "build_element",
    "validate",
    "update_post",
    "set_page_css",
]

@lru_cache(maxsize=32)
def get_config(client: str) -> dict:
    return clientconfig.load(client)


def _backend(cfg: dict):
    mode = (cfg.get("wp_transport") or os.environ.get("WPBAKERY_TRANSPORT") or "").lower()
    if mode == "rest":
        return _rest
    if mode == "ssh":
        return _ssh
    # Infer: prefer REST when credentials exist, else SSH.
    if _rest.has_creds(cfg):
        return _rest
    if cfg.get("server_ip") and cfg.get("ssh_user"):
        return _ssh
    raise TransportError(
        f"No transport available for '{cfg.get('_name')}'. Provide REST creds "
        "(WPBAKERY_<SLUG>_USER/_APP_PW or wp_rest_user/wp_rest_app_password) or "
        "SSH fields (server_ip, ssh_user, wp_app_slug)."
    )


def _call(client: str, fn_name: str, *args, **kwargs):
    cfg = get_config(client)
    backend = _backend(cfg)
    return getattr(backend, fn_name)(cfg, *args, **kwargs)


# ---- public API (client-slug first; what server.py calls) ------------------

def ping(client):
    return _call(client, "ping")


def list_elements(client):
    return _call(client, "list_elements")


def element(client, tag):
    return _call(client, "element", tag)


def list_posts(client, post_type=None, search=None, limit=None):
    return _call(client, "list_posts", post_type=post_type, search=search, limit=limit)


def get_post(client, post_id):
    return _call(client, "get_post", post_id)


def structure(client, post_id):
    return _call(client, "structure", post_id)


def build_element(client, tag, atts=None, inner=""):
    return _call(client, "build_element", tag, atts=atts, inner=inner)


def validate(client, content):
    return _call(client, "validate", content)


def update_post(client, post_id, content, skip_validate=False, page_css=None):
    return _call(client, "update_post", post_id, content, skip_validate=skip_validate, page_css=page_css)


def set_page_css(client, post_id, css):
    return _call(client, "set_page_css", post_id, css)
