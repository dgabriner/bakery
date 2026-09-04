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
  python3 scripts/cloud_agent_stage.py --smoke

Never point these secrets at bakery.sourflour.org/bake. Live file/DB apply
is the hosted worker after --queue-live writes Staging's approval manifests.
"""
from __future__ import annotations

import argparse
import os
import re
import sys
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
UPLOADER = ROOT / "scripts" / "sftp_upload.py"


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
    import paramiko
    host = os.environ["SFTP_HOST"]
    user = os.environ["SFTP_USER"]
    password = os.environ["SFTP_PASSWORD"]
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(hostname=host, username=user, password=password, timeout=45, allow_agent=False, look_for_keys=False)
    try:
        _stdin, stdout, stderr = client.exec_command(command, timeout=timeout)
        out = stdout.read().decode("utf-8", "replace")
        err = stderr.read().decode("utf-8", "replace")
        code = stdout.channel.recv_exit_status()
        if out.strip():
            print(out.rstrip())
        if err.strip():
            print(err.rstrip(), file=sys.stderr)
        return code
    finally:
        client.close()


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
    parser.add_argument("--smoke", action="store_true", help="Hit Staging login.php")
    parser.add_argument("--check-target", action="store_true")
    args = parser.parse_args()

    if args.check_target:
        return run_uploader(["--check-target"])
    if args.files or args.list:
        extra = ["--list", args.list] if args.list else []
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
