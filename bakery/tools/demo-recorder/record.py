#!/usr/bin/env python3
"""Record a bakery usage scenario to MP4 (Playwright video + ffmpeg)."""
from __future__ import annotations

import argparse
import json
import os
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

TOOL_DIR = Path(__file__).resolve().parent
if str(TOOL_DIR) not in sys.path:
    sys.path.insert(0, str(TOOL_DIR))

from playwright.sync_api import sync_playwright

from runner import placeholders, run_scenario, write_narration_cues
from narrate import mix_narration, prepare_tts, write_json, load_cues


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Record a Sour Flour OS usage walkthrough as MP4")
    parser.add_argument("--scenario", required=True, help="Path to scenario JSON")
    parser.add_argument("--out", required=True, help="Output MP4 path")
    parser.add_argument("--php", default="php", help="php.exe path")
    parser.add_argument("--root", required=True, help="bakery/ directory")
    parser.add_argument("--port", type=int, default=8099)
    parser.add_argument("--headed", action="store_true")
    parser.add_argument("--json", action="store_true")
    parser.add_argument("--base-url", default="", help="Use an already-running local server")
    return parser.parse_args()


def load_scenario(path: str) -> dict:
    with open(path, encoding="utf-8") as handle:
        data = json.load(handle)
    if not isinstance(data, dict) or not data.get("steps"):
        raise SystemExit("Scenario JSON must include steps")
    return data


def pick_port(preferred: int) -> int:
    for port in range(preferred, preferred + 20):
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
            # Exclusive bind so a busy demo port is skipped instead of reused.
            try:
                sock.bind(("127.0.0.1", port))
                return port
            except OSError:
                continue
    raise RuntimeError("No free port for the local PHP demo server")


def server_env(root: str) -> dict[str, str]:
    env = os.environ.copy()
    env["USE_PROD_DB"] = "false"
    env["APP_ENV"] = env.get("APP_ENV") or "local"
    env["BASE_URL"] = "/"
    db_name = (env.get("DB_NAME") or "bakerysf_local").strip()
    if db_name.lower() == "bakerysf":
        raise RuntimeError("Refusing to record against the live production database name")
    env["DB_NAME"] = db_name
    return env


def wait_for_login(base_url: str, timeout: float = 20.0) -> None:
    url = base_url.rstrip("/") + "/login.php"
    deadline = time.time() + timeout
    last_error = ""
    while time.time() < deadline:
        try:
            with urllib.request.urlopen(url, timeout=2) as response:
                if 200 <= response.status < 400:
                    return
                last_error = f"HTTP {response.status}"
        except (urllib.error.URLError, TimeoutError, ConnectionError) as exc:
            last_error = str(exc)
        time.sleep(0.2)
    raise RuntimeError(f"Demo PHP server did not become ready at {url}: {last_error}")


def start_php_server(php_bin: str, root: str, port: int) -> subprocess.Popen:
    return subprocess.Popen(
        [php_bin, "-S", f"127.0.0.1:{port}"],
        cwd=root,
        env=server_env(root),
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )


def convert_to_mp4(webm: str, mp4: str) -> None:
    import imageio_ffmpeg

    ffmpeg = imageio_ffmpeg.get_ffmpeg_exe()
    Path(mp4).parent.mkdir(parents=True, exist_ok=True)
    command = [
        ffmpeg,
        "-y",
        "-i",
        webm,
        "-c:v",
        "libx264",
        "-preset",
        "fast",
        "-crf",
        "20",
        "-pix_fmt",
        "yuv420p",
        "-movflags",
        "+faststart",
        mp4,
    ]
    completed = subprocess.run(command, capture_output=True, text=True)
    if completed.returncode != 0:
        raise RuntimeError(completed.stderr[-2000:] or "ffmpeg failed to write MP4")


def launch_browser(playwright, headed: bool, slow_mo: int):
    last_error: Exception | None = None
    channels = [os.environ.get("DEMO_BROWSER_CHANNEL"), "chrome", "msedge", None]
    seen: set[str] = set()
    for channel in channels:
        key = channel or "chromium"
        if key in seen:
            continue
        seen.add(key)
        try:
            kwargs = {
                "headless": not headed,
                "slow_mo": slow_mo,
                "args": ["--disable-dev-shm-usage"],
            }
            if channel:
                kwargs["channel"] = channel
            browser = playwright.chromium.launch(**kwargs)
            return browser, key
        except Exception as exc:  # noqa: BLE001 - try the next installed browser
            last_error = exc
    raise RuntimeError(f"Could not launch Chrome, Edge, or Chromium: {last_error}")


def main() -> int:
    args = parse_args()
    scenario = load_scenario(args.scenario)
    mapping = placeholders()
    locale = mapping.get("LOCALE") or os.environ.get("DEMO_LOCALE") or "en"
    viewport = scenario.get("viewport") or {"width": 1280, "height": 720}
    width = int(viewport.get("width") or 1280)
    height = int(viewport.get("height") or 720)
    slow_mo = int(scenario.get("slowMo") or 250)

    out_path = Path(args.out).resolve()
    video_dir = out_path.parent / (".tmp-" + out_path.stem)
    tts_dir = Path(args.root) / "storage" / "demo-recordings" / ".tts-cache"
    cues_path = out_path.with_suffix(".cues.json")
    manifest_path = tts_dir / f"manifest-{out_path.stem}.json"
    tts_manifest: dict = {}
    if locale == "es":
        print("Preparing Spanish narrator…", file=sys.stderr)
        tts_manifest = prepare_tts(scenario, "es", tts_dir)
        if not tts_manifest:
            raise RuntimeError("Spanish scenario has no narration captions")
        write_json(manifest_path, tts_manifest)
        os.environ["DEMO_TTS_MANIFEST"] = str(manifest_path)
        os.environ["DEMO_NARRATION_LOG"] = str(cues_path)

    server = None
    if args.base_url:
        base_url = args.base_url.rstrip("/")
    else:
        port = pick_port(int(args.port))
        server = start_php_server(args.php, args.root, port)
        base_url = f"http://127.0.0.1:{port}"

    video_dir.mkdir(parents=True, exist_ok=True)
    webm_path = None
    started = time.time()

    try:
        wait_for_login(base_url)
        with sync_playwright() as playwright:
            browser, channel = launch_browser(playwright, args.headed, slow_mo)
            context = browser.new_context(
                viewport={"width": width, "height": height},
                record_video_dir=str(video_dir),
                record_video_size={"width": width, "height": height},
                locale="es-MX" if os.environ.get("DEMO_LOCALE") == "es" else "en-US",
            )
            page = context.new_page()
            page.set_default_timeout(25000)
            page.set_default_navigation_timeout(45000)
            video = page.video
            dialogs: list[str] = []
            page.on("dialog", lambda dialog: (dialogs.append(dialog.message), dialog.accept()))
            page.on(
                "pageerror",
                lambda err: print(f"PAGE_ERROR {err}", file=sys.stderr),
            )
            page.on(
                "response",
                lambda response: print(
                    f"HTTP {response.status} {response.url}", file=sys.stderr
                )
                if response.status >= 400
                else None,
            )
            try:
                run_scenario(page, scenario, base_url, mapping)
            except Exception:
                if dialogs:
                    print("DIALOG " + " | ".join(dialogs), file=sys.stderr)
                raise
            finally:
                if video is not None:
                    webm_path = video.path()
                page.close()
                context.close()
                browser.close()
        if not webm_path or not os.path.isfile(webm_path):
            raise RuntimeError("Playwright did not write a video file")
        convert_to_mp4(str(webm_path), args.out)
        if locale == "es" and tts_manifest:
            write_narration_cues(str(cues_path))
            cues = load_cues(cues_path)
            if not cues:
                raise RuntimeError("Spanish recording produced no narrator cues")
            mix_narration(args.out, cues, tts_manifest)
    finally:
        if server is not None:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
        if video_dir.is_dir():
            for leftover in video_dir.iterdir():
                leftover.unlink(missing_ok=True)
            video_dir.rmdir()
        if cues_path.exists():
            cues_path.unlink(missing_ok=True)
        if manifest_path.exists():
            manifest_path.unlink(missing_ok=True)

    payload = {
        "ok": True,
        "mp4": os.path.abspath(args.out),
        "scenario": scenario.get("id"),
        "bytes": os.path.getsize(args.out),
        "duration_ms": int((time.time() - started) * 1000),
        "base_url": base_url,
    }
    if args.json:
        print(json.dumps(payload, indent=2))
    else:
        print(f"DEMO_RECORD_OK mp4={payload['mp4']} bytes={payload['bytes']}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except KeyboardInterrupt:
        raise SystemExit(130)
    except Exception as exc:  # noqa: BLE001
        print(f"DEMO_RECORD_FAIL {exc}", file=sys.stderr)
        raise SystemExit(1)
