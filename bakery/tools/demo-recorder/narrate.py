"""Spanish (or English) walkthrough narrator — local TTS mixed into MP4."""
from __future__ import annotations

import asyncio
import hashlib
import json
import os
import re
import subprocess
import sys
from pathlib import Path
from typing import Any

SECRET_DIGIT_RE = re.compile(r"\b\d{4}\b")

EDGE_VOICES = {
    "es": "es-MX-DaliaNeural",
    "en": "en-US-JennyNeural",
}


def ffmpeg_exe() -> str:
    import imageio_ffmpeg

    return imageio_ffmpeg.get_ffmpeg_exe()


def sanitize_speech(text: str) -> str:
    cleaned = SECRET_DIGIT_RE.sub("", str(text or ""))
    return re.sub(r"\s+", " ", cleaned).strip()


def estimate_speech_ms(text: str) -> int:
    spoken = sanitize_speech(text)
    if not spoken:
        return 0
    # Slow bakery-driver pace: ~11 Spanish characters per second, plus a beat.
    return max(900, int(len(spoken) / 11 * 1000) + 450)


def speech_text_for_step(step: dict[str, Any], locale: str) -> str:
    from runner import active_locale, interpolate, localized, placeholders

    loc = locale or active_locale()
    mapping = placeholders()
    raw = step.get("narration") or step.get("caption") or ""
    text = interpolate(localized(raw, loc), mapping, allow_secrets=False)
    if isinstance(text, dict):
        return ""
    return sanitize_speech(str(text))


def collect_speech_lines(scenario: dict[str, Any], locale: str) -> list[str]:
    lines: list[str] = []
    seen: set[str] = set()
    for step in scenario.get("steps") or []:
        text = speech_text_for_step(step, locale)
        if text and text not in seen:
            seen.add(text)
            lines.append(text)
    return lines


def _probe_duration_ms(path: Path) -> int:
    completed = subprocess.run(
        [
            ffmpeg_exe(),
            "-i",
            str(path),
        ],
        capture_output=True,
        text=True,
    )
    blob = (completed.stderr or "") + (completed.stdout or "")
    match = re.search(r"Duration: (\d+):(\d+):(\d+)\.(\d+)", blob)
    if not match:
        return 0
    hours, minutes, seconds, frac = (int(match.group(i)) for i in range(1, 5))
    frac_ms = int(str(frac).ljust(3, "0")[:3])
    return ((hours * 60 + minutes) * 60 + seconds) * 1000 + frac_ms


def _hash_text(text: str) -> str:
    return hashlib.sha1(text.encode("utf-8")).hexdigest()[:12]


def _edge_tts_to_file(text: str, dest: Path, locale: str) -> None:
    import edge_tts

    voice = EDGE_VOICES.get(locale, EDGE_VOICES["es"])
    rate = "-20%" if locale == "es" else "-10%"

    async def _save() -> None:
        communicate = edge_tts.Communicate(text, voice, rate=rate)
        await communicate.save(str(dest))

    asyncio.run(_save())


def _sapi_to_wav(text: str, dest: Path, locale: str) -> None:
    dest.parent.mkdir(parents=True, exist_ok=True)
    escaped = text.replace("'", "''")
    culture = "es*" if locale == "es" else "en*"
    wav = str(dest).replace("'", "''")
    script = f"""
Add-Type -AssemblyName System.Speech
$s = New-Object System.Speech.Synthesis.SpeechSynthesizer
$s.Rate = -2
$pick = $null
foreach ($v in $s.GetInstalledVoices()) {{
  if ($v.VoiceInfo.Culture.Name -like '{culture}') {{
    $pick = $v.VoiceInfo.Name
    break
  }}
}}
if ($pick) {{ $s.SelectVoice($pick) }}
$s.SetOutputToWaveFile('{wav}')
$s.Speak('{escaped}')
$s.Dispose()
"""
    completed = subprocess.run(
        ["powershell", "-NoProfile", "-Command", script],
        capture_output=True,
        text=True,
    )
    if completed.returncode != 0 or not dest.is_file() or dest.stat().st_size < 100:
        err = (completed.stderr or completed.stdout or "SAPI TTS failed").strip()
        raise RuntimeError(err[-800:])


def synthesize(text: str, dest: Path, locale: str) -> Path:
    dest.parent.mkdir(parents=True, exist_ok=True)
    spoken = sanitize_speech(text)
    if not spoken:
        raise ValueError("Empty narration text")
    errors: list[str] = []
    try:
        _edge_tts_to_file(spoken, dest, locale)
        if dest.is_file() and dest.stat().st_size > 100:
            return dest
        errors.append("edge-tts wrote an empty file")
    except Exception as exc:  # noqa: BLE001 — try SAPI next
        errors.append(f"edge-tts: {exc}")
        if dest.exists():
            dest.unlink(missing_ok=True)
    wav_dest = dest.with_suffix(".wav")
    try:
        _sapi_to_wav(spoken, wav_dest, locale)
        if wav_dest.is_file() and wav_dest.stat().st_size > 100:
            return wav_dest
        errors.append("SAPI wrote an empty file")
    except Exception as exc:  # noqa: BLE001
        errors.append(f"SAPI: {exc}")
    raise RuntimeError("Could not synthesize narration (" + "; ".join(errors) + ")")


def prepare_tts(scenario: dict[str, Any], locale: str, cache_dir: Path) -> dict[str, dict[str, Any]]:
    cache_dir.mkdir(parents=True, exist_ok=True)
    manifest: dict[str, dict[str, Any]] = {}
    for text in collect_speech_lines(scenario, locale):
        stem = cache_dir / f"tts-{locale}-{_hash_text(text)}"
        dest = stem.with_suffix(".mp3")
        audio = dest if dest.is_file() and dest.stat().st_size > 100 else synthesize(text, dest, locale)
        duration_ms = _probe_duration_ms(audio) or estimate_speech_ms(text)
        manifest[text] = {
            "wav": str(audio),
            "duration_ms": int(duration_ms),
        }
    return manifest


def write_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False), encoding="utf-8")


def mix_narration(video_mp4: str, cues: list[dict[str, Any]], manifest: dict[str, dict[str, Any]]) -> None:
    """Mix timed TTS clips onto a silent (or existing) MP4 in place."""
    if not cues:
        return
    video = Path(video_mp4)
    if not video.is_file():
        raise RuntimeError("MP4 not found to mix narration")
    ffmpeg = ffmpeg_exe()
    video_ms = _probe_duration_ms(video)
    inputs = ["-i", str(video)]
    filter_parts: list[str] = []
    mix_labels: list[str] = []
    audio_index = 1
    used = 0
    for cue in cues:
        text = sanitize_speech(str(cue.get("text") or ""))
        info = manifest.get(text) or {}
        audio_path = info.get("wav") or cue.get("wav")
        if not audio_path or not os.path.isfile(str(audio_path)):
            continue
        start_ms = max(0, int(cue.get("t_ms") or 0))
        inputs.extend(["-i", str(audio_path)])
        filter_parts.append(
            f"[{audio_index}:a]adelay={start_ms}:all=1,aresample=24000,aformat=channel_layouts=mono[a{used}]"
        )
        mix_labels.append(f"[a{used}]")
        audio_index += 1
        used += 1
    if used == 0:
        raise RuntimeError("Narration cues were recorded but no TTS audio files were found")

    mix_filter = (
        "".join(mix_labels)
        + f"amix=inputs={used}:duration=longest:dropout_transition=0:normalize=0[aout]"
    )
    filter_complex = ";".join(filter_parts + [mix_filter])
    mixed = video.with_name(video.stem + ".narrated.mp4")
    command = [
        ffmpeg,
        "-y",
        *inputs,
        "-filter_complex",
        filter_complex,
        "-map",
        "0:v:0",
        "-map",
        "[aout]",
        "-c:v",
        "copy",
        "-c:a",
        "aac",
        "-b:a",
        "128k",
        "-ac",
        "1",
        "-ar",
        "24000",
        "-movflags",
        "+faststart",
        str(mixed),
    ]
    if video_ms > 0:
        command.extend(["-t", f"{video_ms / 1000:.3f}"])
    completed = subprocess.run(command, capture_output=True, text=True)
    if completed.returncode != 0 or not mixed.is_file():
        raise RuntimeError((completed.stderr or "ffmpeg mix failed")[-2000:])
    os.replace(str(mixed), str(video))


def load_cues(path: Path) -> list[dict[str, Any]]:
    if not path.is_file():
        return []
    data = json.loads(path.read_text(encoding="utf-8"))
    if isinstance(data, dict):
        cues = data.get("cues") or []
        return cues if isinstance(cues, list) else []
    return data if isinstance(data, list) else []


if __name__ == "__main__":
    print("narrate.py is imported by record.py", file=sys.stderr)
    raise SystemExit(0)
