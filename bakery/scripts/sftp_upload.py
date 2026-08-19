"""
Upload a list of relative bakery files over SFTP.

Credentials and remote root come from environment variables (never argv):
  SFTP_HOST, SFTP_USER, SFTP_PASSWORD, SFTP_REMOTE_ROOT
  SFTP_TARGET=dreamhost-stage|dreamhost-live (optional but required for staging)

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


LIVE_REMOTE_ROOTS = ("bakery.sourflour.org/bake",)
STAGING_REMOTE_ROOTS = ("staging.sourflour.org",)


def env_required(name: str) -> str:
    value = (os.environ.get(name) or "").strip()
    if not value:
        raise SystemExit(f"Missing required environment variable: {name}")
    return value


def normalize_remote_root(remote_root: str) -> str:
    return (remote_root or "").strip().strip("/").replace("\\", "/").lower()


def root_matches(root: str, prefixes: tuple[str, ...]) -> bool:
    for prefix in prefixes:
        if root == prefix or root.startswith(prefix + "/"):
            return True
    return False


def assert_sftp_target(user: str, remote_root: str, target: str = "") -> None:
    """Refuse production /bake when the intended target is DreamHost staging."""
    root = normalize_remote_root(remote_root)
    user_stripped = (user or "").strip()
    target_l = (target or "").strip().lower()
    is_live_root = root_matches(root, LIVE_REMOTE_ROOTS)
    is_staging_root = root_matches(root, STAGING_REMOTE_ROOTS)

    if user_stripped.lower() == "bakeryos" and is_live_root:
        raise SystemExit("Refusing SFTP: bakeryOS cannot target bakery.sourflour.org/bake")

    if target_l == "dreamhost-stage":
        if is_live_root:
            raise SystemExit("Refusing staging SFTP: remote root is production bakery.sourflour.org/bake")
        if user_stripped != "bakeryOS":
            raise SystemExit("Refusing staging SFTP: user must be bakeryOS")
        if not is_staging_root:
            raise SystemExit("Refusing staging SFTP: remote root must be staging.sourflour.org")
        return

    if target_l in ("dreamhost-live", "live", "production"):
        if is_staging_root:
            raise SystemExit("Refusing live SFTP: remote root is staging.sourflour.org")
        if not is_live_root:
            raise SystemExit("Refusing live SFTP: remote root must be bakery.sourflour.org/bake")
        if user_stripped.lower() == "bakeryos":
            raise SystemExit("Refusing live SFTP: bakeryOS is the staging user")
        return


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
    parser.add_argument("--local-root", required=True, help="Local bakery root directory")
    parser.add_argument("--list", help="Path to file containing relative paths (default: stdin)")
    parser.add_argument("--dry-run", action="store_true", help="Print actions without connecting")
    parser.add_argument("--check-target", action="store_true", help="Validate SFTP target env and exit")
    parser.add_argument("--from-file", help="Upload one local file (relative or absolute)")
    parser.add_argument("--to-name", help="Remote filename under SFTP_REMOTE_ROOT (with --from-file)")
    args = parser.parse_args()

    host = env_required("SFTP_HOST")
    user = env_required("SFTP_USER")
    password = env_required("SFTP_PASSWORD")
    remote_root = env_required("SFTP_REMOTE_ROOT").strip("/").replace("\\", "/")
    target = (os.environ.get("SFTP_TARGET") or "").strip()
    assert_sftp_target(user, remote_root, target)

    if args.check_target:
        print(f"SFTP target OK  user={user}  root={remote_root}  target={target or '(unset)'}")
        return 0

    local_root = Path(args.local_root).resolve()
    single = None
    files: list[str] = []

    if args.from_file:
        if not args.to_name:
            raise SystemExit("--from-file requires --to-name")
        source = Path(args.from_file)
        if not source.is_absolute():
            source = local_root / args.from_file
        if not source.is_file():
            print(f"Missing local file: {source}", file=sys.stderr)
            return 1
        remote_name = args.to_name.replace("\\", "/").lstrip("/")
        if remote_name in {".env.sftp", ".env.production.pull"} or remote_name.endswith(".sql"):
            raise SystemExit(f"Refusing to upload {remote_name}")
        single = (source, remote_name)
    else:
        files = read_file_list(args.list)
        if not files:
            print("No files to upload.")
            return 0
        missing = [f for f in files if not (local_root / f).is_file()]
        if missing:
            print("Missing local files:", file=sys.stderr)
            for rel in missing:
                print(f"  {rel}", file=sys.stderr)
            return 1

    if args.dry_run:
        if single:
            source, remote_name = single
            print(f"DRY-RUN  {source}  ->  {remote_root}/{remote_name}")
            return 0
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
        if single:
            source, remote_name = single
            remote = f"{remote_root}/{remote_name}"
            ensure_remote_dir(sftp, str(Path(remote).parent).replace("\\", "/"))
            sftp.put(str(source), remote)
            st = sftp.stat(remote)
            print(f"  OK  {remote_name}  ({st.st_size} bytes)")
            uploaded = 1
        else:
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

    total = 1 if single else len(files)
    print(f"Uploaded {uploaded}/{total} file(s) to {remote_root}/")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
