"""Walk a JSON scenario in a recorded browser session."""
from __future__ import annotations

import json
import os
import re
import time
from typing import Any

from playwright.sync_api import Page, TimeoutError as PlaywrightTimeoutError

SECRET_KEYS = {"ADMIN_CODE", "DRIVER_CODE"}

OVERLAY_SCRIPT = """
() => {
  const styleText = `
    #sf-demo-caption, #sf-demo-cursor, #sf-demo-click {
      pointer-events: none !important;
    }
    #sf-demo-caption {
      position: fixed; left: 12px; right: 12px; bottom: 12px; z-index: 2147483646;
      background: rgba(51, 37, 31, 0.92); color: #fffdf8;
      font: 600 14px/1.35 -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      padding: 8px 12px; border-radius: 12px;
      box-shadow: 0 8px 24px rgba(0,0,0,.28);
    }
    #sf-demo-caption.is-top { bottom: auto; top: 12px; }
    .local-env-banner, .prod-db-banner, #hqDebugBtn { display: none !important; }
    #sf-demo-cursor {
      position: fixed; top: 0; left: 0; width: 22px; height: 22px; z-index: 2147483647;
      margin: -11px 0 0 -11px; border: 2px solid #b75c3f; border-radius: 50%;
      background: rgba(183, 92, 63, 0.18); transform: translate(-100px, -100px);
      transition: transform 40ms linear, background 80ms ease;
    }
    #sf-demo-cursor.is-down { background: rgba(183, 92, 63, 0.45); }
    #sf-demo-click {
      position: fixed; width: 12px; height: 12px; margin: -6px 0 0 -6px;
      border: 2px solid #b75c3f; border-radius: 50%; z-index: 2147483645;
      opacity: 0; pointer-events: none;
    }
  `;
  const mount = () => {
    if (!document.head || !document.body) return;
    let style = document.getElementById('sf-demo-overlay-style');
    if (!style) {
      style = document.createElement('style');
      style.id = 'sf-demo-overlay-style';
      document.head.appendChild(style);
    }
    style.textContent = styleText;
    const hideDemoChrome = () => {
      document.querySelectorAll('.local-env-banner, .prod-db-banner, #hqDebugBtn').forEach((el) => {
        el.setAttribute('hidden', 'hidden');
        el.style.display = 'none';
      });
    };
    hideDemoChrome();
    if (!window.__sfDemoBannerWatch) {
      window.__sfDemoBannerWatch = new MutationObserver(hideDemoChrome);
      window.__sfDemoBannerWatch.observe(document.documentElement, { childList: true, subtree: true });
    }
    if (!document.getElementById('sf-demo-caption')) {
      const cap = document.createElement('div');
      cap.id = 'sf-demo-caption';
      cap.textContent = window.__sfDemoCaption || '';
      cap.style.display = window.__sfDemoCaption ? 'block' : 'none';
      cap.classList.toggle('is-top', window.__sfDemoCaptionPos === 'top');
      document.body.appendChild(cap);
    }
    if (!document.getElementById('sf-demo-cursor')) {
      const cursor = document.createElement('div');
      cursor.id = 'sf-demo-cursor';
      document.body.appendChild(cursor);
      const ring = document.createElement('div');
      ring.id = 'sf-demo-click';
      document.body.appendChild(ring);
      document.addEventListener('mousemove', (event) => {
        cursor.style.transform = `translate(${event.clientX}px, ${event.clientY}px)`;
      }, true);
      document.addEventListener('mousedown', (event) => {
        cursor.classList.add('is-down');
        ring.style.left = event.clientX + 'px';
        ring.style.top = event.clientY + 'px';
        ring.animate(
          [{ transform: 'scale(1)', opacity: 0.8 }, { transform: 'scale(4)', opacity: 0 }],
          { duration: 380, easing: 'ease-out' }
        );
      }, true);
      document.addEventListener('mouseup', () => cursor.classList.remove('is-down'), true);
    }
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount, { once: true });
  } else {
    mount();
  }
}
"""


def placeholders(env: dict[str, str] | None = None) -> dict[str, str]:
    source = env or os.environ
    return {
        "ADMIN_CODE": source.get("DEMO_ADMIN_CODE", ""),
        "DRIVER_CODE": source.get("DEMO_DRIVER_CODE", ""),
        "DRIVER_ID": source.get("DEMO_DRIVER_ID", ""),
        "DATE": source.get("DEMO_DATE", ""),
        "TODAY": source.get("DEMO_TODAY", ""),
        "TOMORROW": source.get("DEMO_TOMORROW", ""),
        "LOCALE": source.get("DEMO_LOCALE", "en"),
    }


def active_locale(mapping: dict[str, str] | None = None) -> str:
    locale = (mapping or placeholders()).get("LOCALE") or os.environ.get("DEMO_LOCALE") or "en"
    return locale if locale in ("en", "es") else "en"


def localized(value: Any, locale: str) -> Any:
    if isinstance(value, dict) and ("en" in value or "es" in value):
        picked = value.get(locale) or value.get("en") or next(iter(value.values()), "")
        return picked
    return value


def interpolate(value: Any, mapping: dict[str, str], *, allow_secrets: bool) -> Any:
    if isinstance(value, list):
        return [interpolate(item, mapping, allow_secrets=allow_secrets) for item in value]
    if isinstance(value, dict):
        return {key: interpolate(item, mapping, allow_secrets=allow_secrets) for key, item in value.items()}
    if not isinstance(value, str):
        return value

    def repl(match: re.Match[str]) -> str:
        key = match.group(1)
        if key not in mapping:
            raise ValueError(f"Unknown placeholder {{{{{key}}}}}")
        if key in SECRET_KEYS and not allow_secrets:
            return match.group(0)
        resolved = mapping[key]
        if key in SECRET_KEYS and resolved == "":
            raise ValueError(f"Missing {key} — run php scripts/demo_record.php so codes are supplied")
        return resolved

    return re.sub(r"\{\{([A-Z0-9_]+)\}\}", repl, value)


def abs_url(base: str, path: str) -> str:
    if path.startswith("http://") or path.startswith("https://"):
        return path
    return base.rstrip("/") + "/" + path.lstrip("/")


def install_overlays(page: Page) -> None:
    page.add_init_script(OVERLAY_SCRIPT)
    page.evaluate(OVERLAY_SCRIPT)


_NARRATION_T0 = 0.0
_NARRATION_CUES: list[dict[str, Any]] = []
_TTS_MANIFEST: dict[str, Any] = {}


def reset_narration_clock() -> None:
    global _NARRATION_T0, _NARRATION_CUES, _TTS_MANIFEST
    _NARRATION_T0 = time.monotonic()
    _NARRATION_CUES = []
    _TTS_MANIFEST = {}
    manifest_path = os.environ.get("DEMO_TTS_MANIFEST") or ""
    if manifest_path and os.path.isfile(manifest_path):
        try:
            with open(manifest_path, encoding="utf-8") as handle:
                loaded = json.load(handle)
            if isinstance(loaded, dict):
                _TTS_MANIFEST = loaded
        except (OSError, json.JSONDecodeError):
            _TTS_MANIFEST = {}


def narration_cues() -> list[dict[str, Any]]:
    return list(_NARRATION_CUES)


def write_narration_cues(path: str | None = None) -> None:
    dest = path or os.environ.get("DEMO_NARRATION_LOG") or ""
    if not dest:
        return
    payload = {"cues": _NARRATION_CUES}
    with open(dest, "w", encoding="utf-8") as handle:
        json.dump(payload, handle, indent=2, ensure_ascii=False)


def sanitize_speech(text: str) -> str:
    cleaned = re.sub(r"\b\d{4}\b", "", str(text or ""))
    return re.sub(r"\s+", " ", cleaned).strip()


def speech_hold_ms(text: str, caption_hold_ms: int) -> int:
    spoken = sanitize_speech(text)
    hold = int(caption_hold_ms)
    info = _TTS_MANIFEST.get(spoken) or {}
    duration = int(info.get("duration_ms") or 0)
    if duration > 0:
        return max(hold, duration + 400)
    if spoken:
        return max(hold, int(len(spoken) / 11 * 1000) + 450)
    return hold


def note_caption_cue(text: str) -> None:
    spoken = sanitize_speech(text)
    if not spoken or _NARRATION_T0 <= 0:
        return
    _NARRATION_CUES.append(
        {
            "t_ms": int((time.monotonic() - _NARRATION_T0) * 1000),
            "text": spoken,
        }
    )


def set_caption(page: Page, text: str, position: str = "bottom") -> None:
    page.evaluate(
        """({ caption, position }) => {
          window.__sfDemoCaption = caption || '';
          window.__sfDemoCaptionPos = position || 'bottom';
          const run = () => {
            let el = document.getElementById('sf-demo-caption');
            if (!el && document.body) {
              el = document.createElement('div');
              el.id = 'sf-demo-caption';
              document.body.appendChild(el);
            }
            if (!el) return;
            el.textContent = caption || '';
            el.style.display = caption ? 'block' : 'none';
            el.classList.toggle('is-top', position === 'top');
          };
          run();
        }""",
        {"caption": text, "position": position},
    )


def ensure_locale(page: Page, locale: str) -> None:
    # Always hit the switcher so the locale cookie is persisted before staff login.
    # Login defaults to Spanish in the UI without writing the cookie; admin login
    # would otherwise fall back to English.
    selector = f"a.bakery-lang-switch__btn[hreflang='{locale}']"
    loc = page.locator(selector)
    if loc.count() == 0:
        return
    loc.first.click()
    page.wait_for_load_state("domcontentloaded")
    page.wait_for_timeout(400)
    install_overlays(page)


def locator_for(page: Page, step: dict[str, Any]):
    selector = step["selector"]
    loc = page.locator(selector)
    if "nth" in step:
        loc = loc.nth(int(step["nth"]))
    return loc


def run_step(
    page: Page,
    step: dict[str, Any],
    base_url: str,
    mapping: dict[str, str],
    caption_hold_ms: int,
    caption_position: str,
) -> None:
    action = step["action"]
    locale = active_locale(mapping)
    caption = interpolate(localized(step.get("caption") or "", locale), mapping, allow_secrets=False)
    spoken = interpolate(
        localized(step.get("narration") or step.get("caption") or "", locale),
        mapping,
        allow_secrets=False,
    )
    if isinstance(caption, dict):
        caption = ""
    if isinstance(spoken, dict):
        spoken = ""
    caption = str(caption or "")
    spoken = sanitize_speech(str(spoken or ""))
    if "selector" in step:
        step = dict(step)
        step["selector"] = interpolate(str(step["selector"]), mapping, allow_secrets=True)
    if "targetSelector" in step:
        step = dict(step)
        step["targetSelector"] = interpolate(str(step["targetSelector"]), mapping, allow_secrets=True)
    caption_after = action in ("waitForURL", "waitForSelector", "waitForText")
    if caption and not caption_after:
        set_caption(page, caption, caption_position)
        note_caption_cue(spoken or caption)
        hold = speech_hold_ms(spoken or caption, int(step.get("captionHoldMs") or caption_hold_ms))
        if hold > 0:
            page.wait_for_timeout(hold)

    if action == "caption":
        pass
    elif action == "goto":
        path = interpolate(step["path"], mapping, allow_secrets=True)
        if "login.php" in path and "locale=" not in path:
            joiner = "&" if "?" in path else "?"
            path = f"{path}{joiner}locale={locale}"
        page.goto(abs_url(base_url, path), wait_until="domcontentloaded")
        install_overlays(page)
        if "login.php" in path:
            ensure_locale(page, locale)
        if caption:
            set_caption(page, caption, caption_position)
    elif action == "reload":
        page.reload(wait_until="domcontentloaded")
        install_overlays(page)
        if caption:
            set_caption(page, caption, caption_position)
    elif action == "fill":
        loc = locator_for(page, step)
        loc.wait_for(state="visible")
        value = interpolate(str(step["value"]), mapping, allow_secrets=True)
        loc.click()
        loc.fill("")
        delay = int(step.get("delay") or 0)
        if delay > 0:
            loc.press_sequentially(value, delay=delay)
        else:
            loc.fill(value)
    elif action == "click":
        loc = locator_for(page, step)
        loc.scroll_into_view_if_needed()
        if step.get("dismissFileChooser"):
            try:
                with page.expect_file_chooser(timeout=int(step.get("fileChooserTimeout") or 4000)):
                    if step.get("force"):
                        loc.evaluate("el => el.click()")
                    else:
                        loc.click()
            except PlaywrightTimeoutError:
                if loc.count() > 0:
                    pass
            # Do not press Escape: the delivery modal closes on Escape.
        elif step.get("force"):
            loc.evaluate("el => el.click()")
        else:
            loc.click()
    elif action == "clickIf":
        loc = page.locator(step["selector"])
        if loc.count() > 0:
            loc.first.scroll_into_view_if_needed()
            loc.first.click()
            install_overlays(page)
    elif action == "dragTo":
        source = locator_for(page, step)
        target = page.locator(step["targetSelector"])
        if "targetNth" in step:
            target = target.nth(int(step["targetNth"]))
        source.scroll_into_view_if_needed()
        target.scroll_into_view_if_needed()
        source.drag_to(target)
    elif action == "hover":
        locator_for(page, step).hover()
    elif action == "press":
        key = str(step.get("key") or "")
        if not key:
            raise ValueError("press needs key")
        selector = step.get("selector")
        if selector:
            page.locator(selector).press(key)
        else:
            page.keyboard.press(key)
    elif action == "wait":
        page.wait_for_timeout(int(step["ms"]))
    elif action == "waitForSelector":
        state = str(step.get("state") or "visible")
        page.locator(step["selector"]).first.wait_for(state=state)
    elif action == "waitForURL":
        includes = interpolate(str(step.get("includes") or ""), mapping, allow_secrets=True)
        equals = interpolate(str(step.get("equals") or ""), mapping, allow_secrets=True)
        if equals:
            page.wait_for_url(equals)
        else:
            page.wait_for_url(re.compile(re.escape(includes)))
    elif action == "waitForText":
        page.get_by_text(str(step["text"]), exact=bool(step.get("exact"))).first.wait_for()
    elif action == "scroll":
        locator_for(page, step).first.scroll_into_view_if_needed()
    else:
        raise ValueError(f"Unknown action: {action}")

    if caption and caption_after:
        install_overlays(page)
        set_caption(page, caption, caption_position)
        note_caption_cue(spoken or caption)
        hold = speech_hold_ms(spoken or caption, int(step.get("captionHoldMs") or caption_hold_ms))
        if hold > 0:
            page.wait_for_timeout(hold)

    try:
        install_overlays(page)
    except Exception:
        pass

    wait_after = int(step.get("waitAfter") or 400)
    if wait_after > 0:
        page.wait_for_timeout(wait_after)


def run_scenario(page: Page, scenario: dict[str, Any], base_url: str, mapping: dict[str, str]) -> None:
    caption_hold_ms = int(scenario.get("captionHoldMs") or 750)
    caption_position = str(scenario.get("captionPosition") or "bottom")
    reset_narration_clock()
    install_overlays(page)
    for index, step in enumerate(scenario["steps"]):
        try:
            run_step(page, step, base_url, mapping, caption_hold_ms, caption_position)
        except PlaywrightTimeoutError as exc:
            write_narration_cues()
            raise RuntimeError(f"Step {index} ({step.get('action')}) timed out: {exc}") from exc
        except Exception as exc:
            write_narration_cues()
            raise RuntimeError(f"Step {index} ({step.get('action')}) failed: {exc}") from exc
    end_hold = int(scenario.get("endHoldMs") or 1200)
    if _NARRATION_CUES:
        last = _NARRATION_CUES[-1]
        remaining = int(last.get("t_ms") or 0) + speech_hold_ms(str(last.get("text") or ""), 0)
        elapsed = int((time.monotonic() - _NARRATION_T0) * 1000)
        end_hold = max(end_hold, remaining - elapsed + 600)
    if end_hold > 0:
        page.wait_for_timeout(end_hold)
    write_narration_cues()
