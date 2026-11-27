#!/usr/bin/env python3
"""
Cloud-agent Staging (and optional Live queue) helper.

This environment injects staging SFTP secrets:
  SFTP_HOST, SFTP_USER, SFTP_PASSWORD, SFTP_REMOTE_ROOT, SFTP_TARGET

Usage from the bakery/ root:

    python3 scripts/cloud_agent_stage.py --files path1 path2
    python3 scripts/cloud_agent_stage.py --migration database/schema/074_slug.sql
    python3 scripts/cloud_agent_stage.py --migrate-hosted
    python3 scripts/cloud_agent_stage.py --queue-live --migration-id 074_slug.sql --files-live
    python3 scripts/cloud_agent_stage.py --queue-live --files-live-only path1 path2 --commit SHA
    python3 scripts/cloud_agent_stage.py --queue-live --files-live-only --list paths.txt --commit SHA
    python3 scripts/cloud_agent_stage.py --smoke
    python3 scripts/cloud_agent_stage.py --diagnose

Never point these secrets at bakery.sourflour.org/bake. Live file/DB apply
is the hosted worker after --queue-live writes Staging's approval manifests.
"""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
import re
import shlex
import subprocess
import sys
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
UPLOADER = ROOT / "scripts" / "sftp_upload.py"


def _load_sftp_upload():
    spec = importlib.util.spec_from_file_location("bakery_sftp_upload", UPLOADER)
    if spec is None or spec.loader is None:
        raise SystemExit(f"Missing uploader: {UPLOADER}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


sftp_upload = _load_sftp_upload()


def run_uploader(extra: list[str]) -> int:
    cmd = [sys.executable, str(UPLOADER), "--local-root", str(ROOT), *extra]
    os.execv(sys.executable, cmd) if False else None
    import subprocess
    proc = subprocess.run(cmd, cwd=str(ROOT))
    return proc.returncode


def hosted_stage_root() -> str:
    user = (os.environ.get("SFTP_USER") or "").strip()
    remote = (os.environ.get("SFTP_REMOTE_ROOT") or "").strip().strip("/")
    if not user or not remote or "/" in remote:
        raise SystemExit("SFTP_USER / SFTP_REMOTE_ROOT missing or unexpected")
    return f"/home/{user}/{remote}"


def ssh_exec(command: str, timeout: int = 600) -> int:
    code, out, err = ssh_capture(command, timeout)
    if out.strip():
        print(out.rstrip())
    if err.strip():
        print(err.rstrip(), file=sys.stderr)
    return code


def ssh_capture(command: str, timeout: int = 120) -> tuple[int, str, str]:
    paramiko_mod = sftp_upload.require_paramiko()
    settings = sftp_upload.sftp_login_settings()
    sftp_upload.assert_sftp_target(settings["user"], settings["remote_root"], settings["target"])
    host = settings["host"]
    user = settings["user"]
    password = settings["password"]
    client = paramiko_mod.SSHClient()
    client.set_missing_host_key_policy(paramiko_mod.AutoAddPolicy())
    client.connect(hostname=host, username=user, password=password, timeout=45, allow_agent=False, look_for_keys=False)
    try:
        _stdin, stdout, stderr = client.exec_command(command, timeout=timeout)
        out = stdout.read().decode("utf-8", "replace")
        err = stderr.read().decode("utf-8", "replace")
        code = stdout.channel.recv_exit_status()
        return code, out, err
    finally:
        client.close()


FILES_ONLY_BLOCKED = (
    "staging_update.php",
    "oven_light.php",
    "proof_window.php",
    "seed_portal_session_router.php",
    "sf20.html",
)
FILES_ONLY_TOPS = {"includes", "css", "assets", "lang"}


def read_path_list(list_path: str) -> list[str]:
    paths: list[str] = []
    for raw in Path(list_path).read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if line == "" or line.startswith("#"):
            continue
        paths.append(line.replace("\\", "/"))
    return paths


def reject_files_live_only_paths(paths: list[str]) -> list[str]:
    if not paths:
        raise SystemExit("Files-only list is empty.")
    seen: set[str] = set()
    cleaned: list[str] = []
    for path in paths:
        rel = path.replace("\\", "/").strip().lstrip("/")
        if rel == "" or rel in seen:
            raise SystemExit(f"Refusing empty or duplicate files-only path: {path}")
        base = rel.rsplit("/", 1)[-1]
        if base in FILES_ONLY_BLOCKED or base.startswith("tmp_"):
            raise SystemExit(f"Refusing skip-list file: {rel}")
        if rel.startswith("../") or "/../" in rel or rel.startswith(("./", "storage/", "database/", "scripts/", "tests/", "docs/", "uploads/")):
            raise SystemExit(f"Path is outside the promotion set: {rel}")
        if "/" not in rel:
            ext = Path(rel).suffix.lower()
            if rel != ".htaccess" and rel != "staging-robots.txt" and ext not in {".php", ".js", ".css", ".html"}:
                raise SystemExit(f"Path is outside the promotion set: {rel}")
        else:
            top = rel.split("/", 1)[0]
            if top not in FILES_ONLY_TOPS and not rel.startswith("vendor/phpmailer/"):
                raise SystemExit(f"Path is outside the promotion set: {rel}")
        seen.add(rel)
        cleaned.append(rel)
    return cleaned


def git_file_sha256(commit: str, bakery_rel: str) -> str:
    repo = ROOT.parent
    shown = subprocess.run(
        ["git", "show", f"{commit}:bakery/{bakery_rel}"],
        cwd=repo,
        check=False,
        capture_output=True,
    )
    if shown.returncode != 0:
        detail = shown.stderr.decode("utf-8", "replace").strip()
        raise SystemExit(f"git show failed for bakery/{bakery_rel} at {commit}: {detail}")
    return hashlib.sha256(shown.stdout).hexdigest()


def resolve_commit(commit: str) -> str:
    if not re.fullmatch(r"[0-9a-fA-F]{7,40}", commit or ""):
        raise SystemExit("Files-only promotion requires a commit SHA.")
    shown = subprocess.run(
        ["git", "rev-parse", "--verify", f"{commit}^{{commit}}"],
        cwd=ROOT.parent,
        check=False,
        capture_output=True,
        text=True,
    )
    if shown.returncode != 0:
        raise SystemExit(f"Not a commit: {commit}")
    return shown.stdout.strip().lower()


def staging_file_sha256(paths: list[str]) -> dict[str, str]:
    root = hosted_stage_root()
    quoted = " ".join(shlex.quote(path) for path in paths)
    code, out, err = ssh_capture(f"cd {shlex.quote(root)} && sha256sum {quoted}")
    if code != 0:
        raise SystemExit(err.strip() or "Could not hash Staging files.")
    found: dict[str, str] = {}
    for line in out.splitlines():
        parts = line.split()
        if len(parts) < 2 or not re.fullmatch(r"[0-9a-f]{64}", parts[0]):
            continue
        rel = parts[-1].lstrip("./")
        found[rel] = parts[0]
    missing = [path for path in paths if path not in found]
    if missing:
        raise SystemExit("Missing Staging hash for: " + ", ".join(missing))
    return found


def queue_files_live_only(paths: list[str], commit: str) -> int:
    cleaned = reject_files_live_only_paths(paths)
    full_commit = resolve_commit(commit)
    expected = {path: git_file_sha256(full_commit, path) for path in cleaned}
    actual = staging_file_sha256(cleaned)
    mismatched = [path for path in cleaned if actual[path] != expected[path]]
    if mismatched:
        print("Staging hash does not match " + full_commit + " for:", file=sys.stderr)
        for path in mismatched:
            print(f"  {path}", file=sys.stderr)
        return 1
    payload = {
        "commit": full_commit,
        "files": [{"path": path, "sha256": expected[path]} for path in cleaned],
    }
    manifest_local = Path("/tmp/bakery_files_live_only.json")
    manifest_local.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
    tool = ROOT / "scripts" / "queue_hosted_live.php"
    code = run_uploader(["--from-file", str(tool), "--to-name", "../.sourflour-stage-tools/queue_hosted_live.php"])
    if code != 0:
        return code
    code = run_uploader(["--from-file", str(manifest_local), "--to-name", "../.sourflour-stage-tools/files_live_only.json"])
    if code != 0:
        return code
    root = hosted_stage_root()
    remote_manifest = root + "/../.sourflour-stage-tools/files_live_only.json"
    remote_tool = root + "/../.sourflour-stage-tools/queue_hosted_live.php"
    command = (
        "umask 077 && "
        f"export BAKERY_HOSTED_STAGE_ROOT={shlex.quote(root)} && "
        f"php {shlex.quote(remote_tool)} --confirm-live --files-only-manifest={shlex.quote(remote_manifest)}"
    )
    return ssh_exec(command)


def smoke() -> int:
    host = (os.environ.get("SFTP_REMOTE_ROOT") or "").strip().strip("/").split("/")[0]
    url = f"https://{host}/login.php"
    req = urllib.request.Request(url, headers={"User-Agent": "SourFlour-CloudAgent-Smoke/1"})
    with urllib.request.urlopen(req, timeout=45) as resp:
        body = resp.read().decode("utf-8", "replace")
        ok = resp.status == 200 and "staging-env-banner" in body and "bakerysoftware" in body
        print(f"Smoke {url} HTTP {resp.status} {'OK' if ok else 'FAIL'}")
        return 0 if ok else 1


def main() -> int:
    parser = argparse.ArgumentParser(description="Cloud-agent Staging / Live queue")
    parser.add_argument("--files", nargs="*", default=[], help="Relative bakery files to upload")
    parser.add_argument("--list", help="File list (one relative path per line)")
    parser.add_argument("--migration", help="Publish one database/schema/NNN_*.sql to the Staging vault")
    parser.add_argument("--migrate-hosted", action="store_true", help="Checkpoint bakerysoftware and apply hosted-stage migrations")
    parser.add_argument("--queue-live", action="store_true", help="Queue hosted Live after owner said Stage and Live")
    parser.add_argument("--migration-id", help="NNN_slug.sql to approve for Live")
    parser.add_argument("--files-live", action="store_true", help="Queue the Staging file snapshot for Live")
    parser.add_argument("--files-live-only", nargs="*", default=None, help="Queue only these promotion paths for Live")
    parser.add_argument("--commit", default="", help="Git commit whose bytes Staging must match")
    parser.add_argument("--smoke", action="store_true", help="Hit Staging login.php")
    parser.add_argument("--check-target", action="store_true")
    parser.add_argument("--diagnose", action="store_true", help="Check the SFTP login and print non-secret facts")
    args = parser.parse_args()

    live_only = args.files_live_only is not None
    if live_only and args.files_live:
        raise SystemExit("Refusing: --files-live and --files-live-only together.")
    if live_only and not args.queue_live:
        raise SystemExit("Refusing: --files-live-only requires --queue-live.")
    if live_only and args.files_live_only and args.list:
        raise SystemExit("Refusing: pass files-only paths or --list, not both.")

    if args.diagnose:
        return sftp_upload.sftp_login_preflight(diagnose=True)

    if args.check_target:
        return run_uploader(["--check-target"])

    needs_login = bool(
        args.files
        or (bool(args.list) and not live_only)
        or args.migration
        or args.migrate_hosted
        or args.queue_live
    )
    if needs_login:
        preflight_code = sftp_upload.sftp_login_preflight(diagnose=False)
        if preflight_code != 0:
            return preflight_code
    return stage_after_preflight(args, live_only)


def stage_after_preflight(args, live_only: bool) -> int:
    if args.files or (args.list and not live_only):
        extra = ["--list", args.list] if args.list and not live_only else []
        if args.files:
            list_path = Path("/tmp/bakery_cloud_agent_files.txt")
            list_path.write_text("\n".join(args.files) + "\n", encoding="utf-8")
            extra = ["--list", str(list_path)]
        code = run_uploader(extra)
        if code != 0:
            return code
    if args.migration:
        code = run_uploader(["--migration-file", args.migration])
        if code != 0:
            return code
    if args.migrate_hosted:
        code = run_uploader(["--run-hosted-stage-migrations"])
        if code != 0:
            return code
    if args.queue_live:
        if live_only:
            chosen = list(args.files_live_only or [])
            if args.list:
                chosen.extend(read_path_list(args.list))
            code = queue_files_live_only(chosen, args.commit)
            if code != 0:
                return code
        else:
            tool = ROOT / "scripts" / "queue_hosted_live.php"
            if not tool.is_file():
                raise SystemExit("Missing scripts/queue_hosted_live.php")
            code = run_uploader(["--from-file", str(tool), "--to-name", "../.sourflour-stage-tools/queue_hosted_live.php"])
            if code != 0:
                return code
            root = hosted_stage_root()
            flags = ["--confirm-live"]
            if args.migration_id:
                flags.append("--migration=" + Path(args.migration_id).name)
            if args.files_live:
                flags.append("--files")
            command = (
                "umask 077 && "
                f"export BAKERY_HOSTED_STAGE_ROOT={root} && "
                f"php {root}/../.sourflour-stage-tools/queue_hosted_live.php "
                + " ".join(flags)
            )
            code = ssh_exec(command)
            if code != 0:
                return code
    if args.smoke:
        return smoke()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
