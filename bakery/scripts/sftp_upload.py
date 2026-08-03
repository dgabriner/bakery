"""
Upload a list of relative bakery files over SFTP.

Credentials and remote root come from environment variables (never argv):
  SFTP_HOST, SFTP_USER, SFTP_PASSWORD, SFTP_REMOTE_ROOT

File list: one relative path per line on stdin, or --list path.
"""
from __future__ import annotations

import argparse
import os
import sys
from pathlib import Path

try:
    import paramiko
except ImportError:
    print("paramiko is required. Install with: py -m pip install paramiko", file=sys.stderr)
    sys.exit(2)


def env_required(name: str) -> str:
    value = (os.environ.get(name) or "").strip()
    if not value:
        raise SystemExit(f"Missing required environment variable: {name}")
    return value


def ensure_remote_dir(sftp: "paramiko.SFTPClient", remote_dir: str) -> None:
    parts = remote_dir.replace("\\", "/").strip("/").split("/")
    path = ""
    for part in parts:
        path = f"{path}/{part}" if path else part
        try:
            sftp.stat(path)
        except FileNotFoundError:
            sftp.mkdir(path)


def read_file_list(list_path: str | None) -> list[str]:
    if list_path:
        text = Path(list_path).read_text(encoding="utf-8")
    else:
        text = sys.stdin.read()
    files: list[str] = []
    for line in text.splitlines():
        rel = line.strip().lstrip("\ufeff").replace("\\", "/")
        if not rel or rel.startswith("#"):
            continue
        files.append(rel)
    return files


def main() -> int:
    parser = argparse.ArgumentParser(description="SFTP upload bakery deploy files")
    parser.add_argument(
        "--local-root",
        required=True,
        help="Local bakery root directory",
    )
    parser.add_argument(
        "--list",
        help="Path to file containing relative paths (default: stdin)",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Print actions without connecting",
    )
    args = parser.parse_args()

    local_root = Path(args.local_root).resolve()
    files = read_file_list(args.list)
    if not files:
        print("No files to upload.")
        return 0

    host = env_required("SFTP_HOST")
    user = env_required("SFTP_USER")
    password = env_required("SFTP_PASSWORD")
    remote_root = env_required("SFTP_REMOTE_ROOT").strip("/").replace("\\", "/")

    missing = [f for f in files if not (local_root / f).is_file()]
    if missing:
        print("Missing local files:", file=sys.stderr)
        for rel in missing:
            print(f"  {rel}", file=sys.stderr)
        return 1

    if args.dry_run:
        for rel in files:
            print(f"DRY-RUN  {rel}  ->  {remote_root}/{rel}")
        print(f"Would upload {len(files)} file(s) to {host}:{remote_root}/")
        return 0

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting to {host} as {user}...")
    client.connect(
        hostname=host,
        username=user,
        password=password,
        timeout=45,
        allow_agent=False,
        look_for_keys=False,
    )
    sftp = client.open_sftp()

    uploaded = 0
    try:
        for rel in files:
            local = local_root / rel
            remote = f"{remote_root}/{rel}"
            ensure_remote_dir(sftp, str(Path(remote).parent).replace("\\", "/"))
            sftp.put(str(local), remote)
            st = sftp.stat(remote)
            print(f"  OK  {rel}  ({st.st_size} bytes)")
            uploaded += 1
    finally:
        sftp.close()
        client.close()

    print(f"Uploaded {uploaded}/{len(files)} file(s) to {remote_root}/")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
