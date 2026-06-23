#!/usr/bin/env python3
"""Screenshot a URL to PNG with headless Chrome — reliably.

Headless Chrome often writes the screenshot but then doesn't exit (the hang that
causes "timeout" failures). This backgrounds Chrome, polls for the output file,
then kills it. Use it on a wpbakery_render_preview `preview_url` to *see* a page
(drafts included), then open the PNG to judge whether it matches the request.

Usage:
    python screenshot.py <url> [out.png] [--width 1440] [--height 4000]
                         [--scale 1] [--timeout 45]

Examples:
    python screenshot.py "https://site.com/?page_id=42&mcp_preview=abc" preview.png
    python screenshot.py "https://site.com/page/" shot.png --height 6500   # long page

Set CHROME=/path/to/chrome to override auto-detection.
"""
from __future__ import annotations

import argparse
import os
import shutil
import subprocess
import sys
import tempfile
import time
from pathlib import Path

CANDIDATES = [
    os.environ.get("CHROME"),
    "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
    "/Applications/Chromium.app/Contents/MacOS/Chromium",
    "/Applications/Google Chrome Canary.app/Contents/MacOS/Google Chrome Canary",
    "/Applications/Brave Browser.app/Contents/MacOS/Brave Browser",
    shutil.which("google-chrome"),
    shutil.which("google-chrome-stable"),
    shutil.which("chromium"),
    shutil.which("chromium-browser"),
    shutil.which("chrome"),
    shutil.which("brave-browser"),
]


def find_chrome() -> str | None:
    for c in CANDIDATES:
        if c and Path(c).exists():
            return c
    return None


def main() -> None:
    ap = argparse.ArgumentParser(description="Reliable headless-Chrome screenshot.")
    ap.add_argument("url")
    ap.add_argument("out", nargs="?", default="screenshot.png")
    ap.add_argument("--width", type=int, default=1440)
    ap.add_argument("--height", type=int, default=4000, help="viewport height; raise for long pages")
    ap.add_argument("--scale", type=float, default=1.0, help="device scale factor (2 = retina)")
    ap.add_argument("--timeout", type=int, default=45)
    args = ap.parse_args()

    chrome = find_chrome()
    if not chrome:
        sys.exit("No Chrome/Chromium found. Install Chrome or set CHROME=/path/to/chrome.")

    out = Path(args.out).resolve()
    if out.exists():
        out.unlink()
    profile = tempfile.mkdtemp(prefix="mcpshot-")

    cmd = [
        chrome,
        "--headless=new",
        "--disable-gpu",
        "--hide-scrollbars",
        "--no-first-run",
        "--no-default-browser-check",
        "--disable-extensions",
        f"--force-device-scale-factor={args.scale}",
        f"--user-data-dir={profile}",
        f"--window-size={args.width},{args.height}",
        "--virtual-time-budget=8000",
        f"--screenshot={out}",
        args.url,
    ]
    proc = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        deadline = time.time() + args.timeout
        while time.time() < deadline:
            if out.exists() and out.stat().st_size > 0:
                time.sleep(0.4)  # let the write finish
                break
            if proc.poll() is not None:
                break
            time.sleep(0.3)
    finally:
        if proc.poll() is None:
            proc.terminate()
            try:
                proc.wait(timeout=5)
            except subprocess.TimeoutExpired:
                proc.kill()
        shutil.rmtree(profile, ignore_errors=True)

    if out.exists() and out.stat().st_size > 0:
        print(f"✓ wrote {out} ({out.stat().st_size} bytes, {args.width}x{args.height} @{args.scale}x)")
        print("  Open it (or Read it in Claude Code) to inspect the rendered page.")
    else:
        sys.exit(
            "✗ screenshot failed. Try a larger --timeout, confirm the URL loads "
            "in a browser, or set CHROME=/path/to/chrome."
        )


if __name__ == "__main__":
    main()
