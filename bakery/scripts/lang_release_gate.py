#!/usr/bin/env python3
"""
Refuse a language catalog that would drop keys Staging or Live still has,
or that PHP on that host still uses.

Fixture decisions do not open a network connection. The upload and Live queue
commands call the same functions with the catalogs they just read.
"""
from __future__ import annotations

import os
import re
import subprocess
import sys
from pathlib import Path

LANG_FILES = ("lang/en.php", "lang/es.php")

_CATALOG_KEY = re.compile(r"^\s*'((?:\\'|[^'])*)'\s*=>", re.M)
_CALL_KEY = re.compile(r"bakery_t(?:e)?\(\s*'([^']+)'")
_QUOTED_KEY = re.compile(r"'([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)'")


def parse_catalog_keys(text: str) -> set[str]:
    keys: set[str] = set()
    for raw in _CATALOG_KEY.findall(text):
        keys.add(raw.replace("\\'", "'"))
    return keys


def php_used_keys(text: str) -> set[str]:
    """Literal translation keys a PHP file asks the catalog for."""
    keys: set[str] = set()
    for key in _CALL_KEY.findall(text):
        if key.endswith("."):
            continue
        keys.add(key)
    for key in _QUOTED_KEY.findall(text):
        if key.endswith(".php") or key.endswith("."):
            continue
        keys.add(key)
    if "'mode.' . $mode" in text:
        for mode in ("full", "driver", "production"):
            keys.add("mode." + mode)
            keys.add("mode." + mode + "_short")
    if "'formula.units.'" in text:
        for unit in ("g", "lb", "gal", "all", "g_aria", "lb_aria", "gal_aria", "all_aria"):
            keys.add("formula.units." + unit)
    if "'nav.sales_short.'" in text:
        for page in ("sales_home", "sales_leads", "sales_customers"):
            keys.add("nav.sales_short." + page)
    return keys


def php_tree_keys(root: Path) -> set[str]:
    keys: set[str] = set()
    if not root.is_dir():
        return keys
    skip = {"vendor", "storage", "node_modules", ".git"}
    for path in root.rglob("*.php"):
        if any(part in skip for part in path.parts):
            continue
        try:
            text = path.read_text(encoding="utf-8", errors="replace")
        except OSError:
            continue
        keys |= php_used_keys(text)
    return keys


def dropped_keys(new_text: str, current_text: str, used_keys: set[str]) -> list[str]:
    """Keys the new catalog removes that the current catalog or PHP still needs."""
    protected = parse_catalog_keys(current_text) | set(used_keys)
    return sorted(protected - parse_catalog_keys(new_text))


def actor_label() -> str:
    user = (os.environ.get("USER") or os.environ.get("LOGNAME") or "unknown").strip()
    name = ""
    try:
        shown = subprocess.run(
            ["git", "config", "user.name"],
            check=False,
            capture_output=True,
            text=True,
        )
        if shown.returncode == 0:
            name = shown.stdout.strip()
    except OSError:
        name = ""
    sftp_user = (os.environ.get("SFTP_USER") or "").strip()
    parts = [user]
    if name:
        parts.append(name)
    if sftp_user:
        parts.append("sftp:" + sftp_user)
    return " ".join(parts)


def decide(
    rel: str,
    new_text: str,
    current_text: str,
    used_keys: set[str],
    allow: set[str],
    reason: str,
    actor: str,
) -> int:
    """Print the refusal or the override audit line. Return 0 only when allowed."""
    missing = dropped_keys(new_text, current_text, used_keys)
    if not missing:
        return 0
    permitted = [key for key in missing if key in allow]
    refused = [key for key in missing if key not in allow]
    if refused:
        print(f"Refusing {rel}: this catalog would drop keys the current copy has or PHP still uses:", file=sys.stderr)
        for key in refused:
            print(f"  {key}", file=sys.stderr)
        return 1
    why = reason.strip()
    if why == "":
        print(f"Refusing {rel}: --allow-drop-keys needs --allow-drop-reason.", file=sys.stderr)
        return 1
    print(
        "LANG KEY DROP ALLOWED by "
        + actor
        + " reason="
        + why
        + " file="
        + rel
        + " keys="
        + ",".join(permitted),
        file=sys.stderr,
    )
    return 0


def parse_allow_list(raw: str) -> set[str]:
    return {part.strip() for part in (raw or "").split(",") if part.strip()}


def is_lang_file(path: str) -> bool:
    rel = path.replace("\\", "/").lstrip("/")
    return rel in LANG_FILES


def read_sftp_text(sftp, remote: str) -> str:
    try:
        with sftp.open(remote, "r") as handle:
            data = handle.read()
    except OSError:
        return ""
    except Exception as exc:
        message = str(exc)
        if "No such file" in message or "not found" in message.lower():
            return ""
        raise
    if isinstance(data, bytes):
        return data.decode("utf-8", "replace")
    return str(data)


def remote_php_keys(client, remote_root: str) -> set[str]:
    """Ask remote PHP which translation keys the tree uses. Read only."""
    import shlex
    script = r"""<?php
$root = $argv[1] ?? '';
if ($root === '' || !is_dir($root)) { fwrite(STDERR, "missing root\n"); exit(2); }
$skip = ['vendor' => true, 'storage' => true, 'node_modules' => true, '.git' => true];
$keys = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
    $blocked = false;
    foreach (explode('/', str_replace('\\', '/', $file->getPathname())) as $part) {
        if (isset($skip[$part])) { $blocked = true; break; }
    }
    if ($blocked) continue;
    $text = (string)@file_get_contents($file->getPathname());
    if (preg_match_all("/bakery_t(?:e)?\\(\\s*'([^']+)'/", $text, $calls)) {
        foreach ($calls[1] as $key) { if (substr($key, -1) !== '.') $keys[$key] = true; }
    }
    if (preg_match_all("/'([a-z][a-z0-9_]*(?:\\.[a-z0-9_]+)+)'/", $text, $quoted)) {
        foreach ($quoted[1] as $key) {
            if (substr($key, -4) === '.php' || substr($key, -1) === '.') continue;
            $keys[$key] = true;
        }
    }
    if (strpos($text, "'mode.' . \$mode") !== false) {
        foreach (['full','driver','production'] as $mode) { $keys['mode.'.$mode] = true; $keys['mode.'.$mode.'_short'] = true; }
    }
    if (strpos($text, "'formula.units.'") !== false) {
        foreach (['g','lb','gal','all','g_aria','lb_aria','gal_aria','all_aria'] as $unit) $keys['formula.units.'.$unit] = true;
    }
    if (strpos($text, "'nav.sales_short.'") !== false) {
        foreach (['sales_home','sales_leads','sales_customers'] as $page) $keys['nav.sales_short.'.$page] = true;
    }
}
ksort($keys);
echo implode("\n", array_keys($keys));
"""
    command = "php -r " + shlex.quote(script) + " " + shlex.quote(remote_root)
    _stdin, stdout, stderr = client.exec_command(command, timeout=180)
    out = stdout.read().decode("utf-8", "replace")
    err = stderr.read().decode("utf-8", "replace")
    code = stdout.channel.recv_exit_status()
    if code != 0:
        raise RuntimeError(err.strip() or "Could not read translation keys from PHP on the server.")
    return {line.strip() for line in out.splitlines() if line.strip()}


def guard_connected(client, sftp, remote_root: str, local_root: Path, pairs: list[tuple[str, Path]], allow: set[str], reason: str) -> int:
    """Refuse before any language file is uploaded. pairs are (remote rel, local path)."""
    if not pairs:
        return 0
    try:
        used = remote_php_keys(client, remote_root)
    except Exception as exc:
        message = str(exc)
        if "429" in message:
            print("Stopping: the server returned 429. Not retrying.", file=sys.stderr)
        else:
            print("Refusing language upload: could not read PHP keys on the server.", file=sys.stderr)
            print(message, file=sys.stderr)
        return 1
    who = actor_label()
    root = remote_root.rstrip("/")
    for rel, local in pairs:
        try:
            new_text = local.read_text(encoding="utf-8", errors="replace")
            current = read_sftp_text(sftp, root + "/" + rel)
        except Exception as exc:
            if "429" in str(exc):
                print("Stopping: the server returned 429. Not retrying.", file=sys.stderr)
            else:
                print(f"Refusing {rel}: could not read the current catalog.", file=sys.stderr)
            return 1
        code = decide(rel, new_text, current, used, allow, reason, who)
        if code != 0:
            return code
    return 0
