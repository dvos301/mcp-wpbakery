"""REST backend for the MCP WPBakery bridge.

Talks to the companion plugin's REST routes under
    <base_url>/wp-json/mcp-wpbakery/v1/
using HTTP Basic auth with a WordPress Application Password.

Credentials (checked in this order):
  1. env  WPBAKERY_<SLUG>_USER   / WPBAKERY_<SLUG>_APP_PW
  2. env  WPBAKERY_REST_USER     / WPBAKERY_REST_APP_PW
  3. cfg  wp_rest_user           / wp_rest_app_password

Application Password spaces are ignored (WordPress accepts them stripped).
Uses only the standard library (urllib) — no extra dependencies.
"""

from __future__ import annotations

import base64
import json
import os
import re
import urllib.error
import urllib.parse
import urllib.request

from .errors import RemoteError, TransportError

TIMEOUT = 60


def _slug_env(cfg: dict) -> str:
    return re.sub(r"[^A-Z0-9]", "_", str(cfg.get("_name", "")).upper())


def _creds(cfg: dict) -> tuple[str | None, str | None]:
    slug = _slug_env(cfg)
    user = (
        os.environ.get(f"WPBAKERY_{slug}_USER")
        or os.environ.get("WPBAKERY_REST_USER")
        or cfg.get("wp_rest_user")
    )
    pw = (
        os.environ.get(f"WPBAKERY_{slug}_APP_PW")
        or os.environ.get("WPBAKERY_REST_APP_PW")
        or cfg.get("wp_rest_app_password")
    )
    if pw:
        pw = pw.replace(" ", "")
    return user, pw


def has_creds(cfg: dict) -> bool:
    user, pw = _creds(cfg)
    return bool(user and pw)


def _base(cfg: dict) -> str:
    base = cfg.get("rest_base_url") or cfg.get("base_url")
    if not base:
        raise TransportError(
            f"Client '{cfg.get('_name')}' has no base_url/rest_base_url for REST."
        )
    return base.rstrip("/") + "/wp-json/mcp-wpbakery/v1"


def _request(cfg: dict, method: str, path: str, body: dict | None = None):
    user, pw = _creds(cfg)
    if not (user and pw):
        raise TransportError(
            f"No REST credentials for '{cfg.get('_name')}'. Set "
            f"WPBAKERY_{_slug_env(cfg)}_USER and WPBAKERY_{_slug_env(cfg)}_APP_PW "
            "(or wp_rest_user / wp_rest_app_password in the client config)."
        )

    url = _base(cfg) + path
    data = json.dumps(body).encode("utf-8") if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    token = base64.b64encode(f"{user}:{pw}".encode()).decode("ascii")
    req.add_header("Authorization", f"Basic {token}")
    req.add_header("Accept", "application/json")
    if data is not None:
        req.add_header("Content-Type", "application/json")

    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as resp:
            payload = json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        raw = e.read().decode("utf-8", "replace")
        try:
            payload = json.loads(raw)
        except Exception:
            raise TransportError(f"HTTP {e.code} from {url}: {raw[:400]}") from e
        # Plugin error envelope, or a generic WP REST error.
        msg = payload.get("error") or payload.get("message") or raw[:400]
        if e.code in (401, 403):
            raise TransportError(f"Auth failed (HTTP {e.code}): {msg}") from e
        raise RemoteError(msg)
    except urllib.error.URLError as e:
        raise TransportError(f"Could not reach {url}: {e.reason}") from e

    if isinstance(payload, dict) and "ok" in payload:
        if not payload["ok"]:
            raise RemoteError(payload.get("error", "unknown error"))
        return payload.get("data")
    return payload


# ---- backend API (cfg-first) ----------------------------------------------

def ping(cfg):
    return _request(cfg, "GET", "/ping")


def list_elements(cfg):
    return _request(cfg, "GET", "/elements")


def element(cfg, tag):
    safe = urllib.parse.quote(re.sub(r"[^a-zA-Z0-9_-]", "", tag))
    return _request(cfg, "GET", f"/elements/{safe}")


def list_posts(cfg, post_type=None, search=None, limit=None):
    q = {}
    if post_type:
        q["post_type"] = post_type
    if search:
        q["search"] = search
    if limit:
        q["limit"] = int(limit)
    qs = ("?" + urllib.parse.urlencode(q)) if q else ""
    return _request(cfg, "GET", f"/posts{qs}")


def get_post(cfg, post_id):
    return _request(cfg, "GET", f"/posts/{int(post_id)}")


def structure(cfg, post_id):
    data = get_post(cfg, post_id)
    return data.get("structure", []) if isinstance(data, dict) else data


def build_element(cfg, tag, atts=None, inner=""):
    return _request(
        cfg, "POST", "/build",
        {"tag": tag, "atts": atts or {}, "content": inner},
    )


def validate(cfg, content):
    return _request(cfg, "POST", "/validate", {"content": content})


def update_post(cfg, post_id, content, skip_validate=False, page_css=None):
    body = {"content": content, "validate": not skip_validate}
    if page_css is not None:
        body["page_css"] = page_css
    return _request(cfg, "POST", f"/posts/{int(post_id)}", body)


def set_page_css(cfg, post_id, css):
    return _request(cfg, "POST", f"/posts/{int(post_id)}/page-css", {"css": css})
