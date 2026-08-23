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
import time
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
    parser.add_argument("--fetch", action="store_true", help="Download files from the remote root into --local-root")
    parser.add_argument("--from-file", help="Upload one local file (relative or absolute)")
    parser.add_argument("--to-name", help="Remote filename under SFTP_REMOTE_ROOT (with --from-file)")
    parser.add_argument("--migration-file", help="Upload one approved schema SQL file to Staging's private migration vault")
    parser.add_argument("--run-hosted-stage-migrations", action="store_true", help="Checkpoint and migrate bakerysoftware through the Staging SSH account")
    parser.add_argument("--bootstrap-live-migration-worker", action="store_true", help="Atomically install the owner-approved Live migration wrapper and runtime")
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

    if args.fetch and (args.from_file or args.migration_file or args.run_hosted_stage_migrations or args.bootstrap_live_migration_worker):
        raise SystemExit("--fetch cannot be combined with --from-file")
    if (args.run_hosted_stage_migrations or args.bootstrap_live_migration_worker) and (args.from_file or args.migration_file):
        raise SystemExit("Hosted worker operations cannot be combined with a single-file operation")

    if args.bootstrap_live_migration_worker:
        if target.strip().lower() != "dreamhost-live" or user.lower() == "bakeryos" or normalize_remote_root(remote_root) != "bakery.sourflour.org/bake":
            raise SystemExit("Live worker bootstrap requires the Live account at exactly bakery.sourflour.org/bake")
        artifacts = [
            (local_root / "includes" / "hosted_migration_runtime.php", f"{remote_root}/includes/hosted_migration_runtime.php", 0o644),
            (local_root / "scripts" / "hosted_migration_worker.php", f"{remote_root}/../../bin/hosted_migration_worker.php", 0o700),
        ]
        if any(not local.is_file() for local, _remote, _mode in artifacts):
            raise SystemExit("Live migration bootstrap artifacts are incomplete")
        if args.dry_run:
            for local, remote, _mode in artifacts:
                print(f"DRY-RUN  atomic bootstrap {local} -> {remote}")
            return 0
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        client.connect(hostname=host, username=user, password=password, timeout=45, allow_agent=False, look_for_keys=False)
        sftp = client.open_sftp()
        stamp = time.strftime("%Y%m%d-%H%M%S", time.gmtime())
        try:
            for local, remote, mode in artifacts:
                ensure_remote_dir(sftp, str(Path(remote).parent).replace("\\", "/"))
                temporary = remote + f".bootstrap-tmp-{stamp}"
                backup = remote + f".bootstrap-backup-{stamp}"
                sftp.put(str(local), temporary)
                if sftp.stat(temporary).st_size != local.stat().st_size:
                    raise RuntimeError(f"Bootstrap size verification failed for {remote}")
                sftp.chmod(temporary, mode)
                had_prior = True
                try:
                    sftp.stat(remote)
                except FileNotFoundError:
                    had_prior = False
                if had_prior:
                    sftp.rename(remote, backup)
                try:
                    sftp.rename(temporary, remote)
                except Exception:
                    if had_prior:
                        sftp.rename(backup, remote)
                    raise
                print(f"BOOTSTRAP  {remote}  backup={'none' if not had_prior else backup}")
            return 0
        finally:
            sftp.close()
            client.close()

    if args.run_hosted_stage_migrations:
        if target.strip().lower() != "dreamhost-stage" or user != "bakeryOS" or normalize_remote_root(remote_root) != "staging.sourflour.org":
            raise SystemExit("Hosted Staging migrations require bakeryOS at exactly staging.sourflour.org")
        tools = [
            local_root / "scripts" / "snapshot_dreamhost_staging.php",
            local_root / "scripts" / "run_migrations.php",
            local_root / "scripts" / "prod_db_cli.php",
        ]
        if any(not tool.is_file() for tool in tools):
            raise SystemExit("Hosted Staging migration tools are incomplete")
        if args.dry_run:
            print("DRY-RUN  upload private Staging snapshot and migration tools")
            print("DRY-RUN  checkpoint bakerysoftware, then run --mode=hosted-stage")
            return 0
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        client.connect(
            hostname=host,
            username=user,
            password=password,
            timeout=45,
            allow_agent=False,
            look_for_keys=False,
        )
        sftp = client.open_sftp()
        private_dir = f"{remote_root}/../.sourflour-stage-tools"
        try:
            ensure_remote_dir(sftp, private_dir)
            for tool in tools:
                remote_tool = f"{private_dir}/{tool.name}"
                sftp.put(str(tool), remote_tool)
                sftp.chmod(remote_tool, 0o600)
            command = (
                "umask 077 && "
                "export BAKERY_HOSTED_STAGE_ROOT=/home/bakeryOS/staging.sourflour.org && "
                "php /home/bakeryOS/.sourflour-stage-tools/snapshot_dreamhost_staging.php --confirm-snapshot-staging && "
                "php /home/bakeryOS/.sourflour-stage-tools/run_migrations.php --mode=hosted-stage"
            )
            _stdin, stdout, stderr = client.exec_command(command, timeout=600)
            stdout_text = stdout.read().decode("utf-8", errors="replace").strip()
            stderr_text = stderr.read().decode("utf-8", errors="replace").strip()
            exit_code = stdout.channel.recv_exit_status()
            if stdout_text:
                print(stdout_text)
            if stderr_text:
                print(stderr_text, file=sys.stderr)
            if exit_code != 0:
                print(f"Hosted Staging migration failed (exit {exit_code}); production bakerysf was not targeted.", file=sys.stderr)
                return exit_code
            print("Hosted Staging checkpoint and migrations succeeded for bakerysoftware.")
            return 0
        finally:
            sftp.close()
            client.close()
    if args.fetch:
        files = read_file_list(args.list)
        if not files:
            print("No files to download.")
            return 0
        if args.dry_run:
            for rel in files:
                print(f"DRY-RUN  fetch {remote_root}/{rel}  ->  {local_root / rel}")
            print(f"Would download {len(files)} file(s) from {host}:{remote_root}/")
            return 0
        if target.strip().lower() != "dreamhost-stage":
            raise SystemExit("Refusing fetch: only dreamhost-stage can be downloaded into a release reconstruction")
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        print(f"Fetching {len(files)} file(s) from {host} as {user}...")
        client.connect(
            hostname=host,
            username=user,
            password=password,
            timeout=45,
            allow_agent=False,
            look_for_keys=False,
        )
        sftp = client.open_sftp()
        downloaded = 0
        try:
            for rel in files:
                local = local_root / rel
                remote = f"{remote_root}/{rel}"
                local.parent.mkdir(parents=True, exist_ok=True)
                sftp.get(remote, str(local))
                st = local.stat()
                print(f"  OK  {rel}  ({st.st_size} bytes)")
                downloaded += 1
        finally:
            sftp.close()
            client.close()
        print(f"Downloaded {downloaded}/{len(files)} file(s) from {remote_root}/")
        return 0

    if args.migration_file:
        if target.strip().lower() != "dreamhost-stage" or user != "bakeryOS":
            raise SystemExit("Private migration upload is allowed only to DreamHost staging as bakeryOS")
        source = Path(args.migration_file)
        if not source.is_absolute():
            source = local_root / args.migration_file
        if not source.is_file() or not source.name.endswith(".sql") or not __import__("re").match(r"^\d{3}_[A-Za-z0-9_]+\.sql$", source.name):
            raise SystemExit("Migration file must be a numbered SQL file such as 050_example.sql")
        single = (source, f"../.sourflour-migration-source/{source.name}")
    elif args.from_file:
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
