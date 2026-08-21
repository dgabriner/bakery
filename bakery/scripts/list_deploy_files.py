#!/usr/bin/env python3
"""
List bakery production deploy files (Python mirror of deploy_manifest.ps1).

Used by GitHub Actions / Linux CI so deploy does not require PowerShell.

Examples:
  python list_deploy_files.py --bakery-root ..
  python list_deploy_files.py --bakery-root .. --all
  python list_deploy_files.py --bakery-root .. --git-from HEAD~1 --git-to HEAD
"""
from __future__ import annotations

import argparse
import fnmatch
import subprocess
import sys
from pathlib import Path

ROOT_FILES = (
    ".htaccess",
    "index.php",
    "login.php",
    "logout.php",
    "baker.php",
    "customers.php",
    "customer_schedule.php",
    "customer_overview.php",
    "customer_routes.php",
    "zones.php",
    "leads.php",
    "pan_dulce_pricing.php",
    "products.php",
    "dough_types.php",
    "formulas.php",
    "ingredients.php",
    "daily_orders.php",
    "standing_orders.php",
    "standing_orders_manager.php",
    "orders.php",
    "bread_distribution.php",
    "product_distribution.php",
    "production.php",
    "pack_list.php",
    "standing_routes.php",
    "daily_route.php",
    "drivers.php",
    "driver.php",
    "driver_list.php",
    "driver_assignment.php",
    "driver_overview.php",
    "route_manager.php",
    "map.php",
    "call_headquarters.php",
    "complete_delivery.php",
    "get_driver_orders.php",
    "get_customer_order_details.php",
    "global_gps_handler.php",
    "upload_driver_photo.php",
    "generate_invoice.php",
    "oauth_callback.php",
    "driver_pages_probe.php",
    "health_driver.php",
    "health_deploy.php",
    "trace_driver_list.php",
    "ping.php",
    "closeout_radar.php",
)

DEPLOY_DIRECTORIES = ("includes", "css", "assets")

EXCLUDE_NAME_PATTERNS = (
    "*_backup.php",
    "*backup.php",
    "*_fixed.php",
    "*_optimized.php",
    "*_working.php",
    "*Copy*.php",
    "debug*.php",
    "simple-debug.php",
    "simple_performance_test.php",
    "health_local.php",
    "health_prod.php",
    "run_sql_setup.php",
    "db_test.php",
    "setup_directories.php",
    "oauth_setup.php",
    "auto_push_api.php",
    "sourflour.html",
)

WEB_ROOT_EXTENSIONS = {".php", ".js", ".css", ".html"}


def skip_file(name: str) -> bool:
    return any(fnmatch.fnmatch(name, pattern) for pattern in EXCLUDE_NAME_PATTERNS)


def is_web_root_file(path: Path) -> bool:
    if skip_file(path.name):
        return False
    if path.name == ".htaccess":
        return True
    return path.suffix.lower() in WEB_ROOT_EXTENSIONS


def list_all_deploy_files(bakery_root: Path, *, include_extra_root: bool = False) -> list[str]:
    """Return deploy paths relative to bakery_root (forward slashes)."""
    files: list[str] = []
    seen: set[str] = set()

    def add(rel: str) -> None:
        norm = rel.replace("\\", "/")
        if norm in seen:
            return
        seen.add(norm)
        files.append(norm)

    for name in ROOT_FILES:
        if (bakery_root / name).is_file():
            add(name)

    if include_extra_root:
        for path in bakery_root.iterdir():
            if path.is_file() and is_web_root_file(path):
                add(path.name)

    for directory in DEPLOY_DIRECTORIES:
        src = bakery_root / directory
        if not src.is_dir():
            continue
        for path in src.rglob("*"):
            if not path.is_file() or skip_file(path.name):
                continue
            add(f"{directory}/{path.relative_to(src).as_posix()}")

    phpmailer = bakery_root / "vendor" / "phpmailer"
    if phpmailer.is_dir():
        for path in phpmailer.rglob("*"):
            if path.is_file():
                add(f"vendor/phpmailer/{path.relative_to(phpmailer).as_posix()}")

    photos_htaccess = bakery_root / "uploads" / "driver_photos" / ".htaccess"
    if photos_htaccess.is_file():
        add("uploads/driver_photos/.htaccess")

    return sorted(files)


def bakery_rel_from_repo_path(repo_path: str, bakery_prefix: str = "bakery/") -> str | None:
    norm = repo_path.replace("\\", "/").lstrip("./")
    if not norm.startswith(bakery_prefix):
        return None
    return norm[len(bakery_prefix) :]


def git_changed_paths(repo_root: Path, git_from: str, git_to: str) -> list[str]:
    cmd = ["git", "-C", str(repo_root), "diff", "--name-only", "--diff-filter=ACMR", git_from, git_to]
    result = subprocess.run(cmd, capture_output=True, text=True, check=False)
    if result.returncode != 0:
        raise SystemExit(
            f"git diff failed ({result.returncode}): {result.stderr.strip() or result.stdout.strip()}"
        )
    return [line.strip() for line in result.stdout.splitlines() if line.strip()]


def filter_deployable(
    candidates: list[str],
    bakery_root: Path,
    *,
    allow_extra_root: bool = True,
) -> list[str]:
    """
    Keep candidates that are deployable.

    Root PHP/JS/CSS/HTML not in ROOT_FILES are included when allow_extra_root
    (matches push_sftp.ps1 AlsoIncludeRootModifiedAfterUtc behavior for CI diffs).
    """
    all_known = set(list_all_deploy_files(bakery_root, include_extra_root=False))
    selected: list[str] = []
    seen: set[str] = set()

    for rel in candidates:
        norm = rel.replace("\\", "/")
        if not norm or norm in seen:
            continue
        local = bakery_root / norm
        if not local.is_file():
            continue
        if norm in all_known:
            seen.add(norm)
            selected.append(norm)
            continue
        # Changed root web file not yet in the static ROOT_FILES list
        if allow_extra_root and "/" not in norm and is_web_root_file(local):
            seen.add(norm)
            selected.append(norm)
            continue
        # Changed file under deploy directories / optional paths
        top = norm.split("/", 1)[0]
        if top in DEPLOY_DIRECTORIES and not skip_file(local.name):
            seen.add(norm)
            selected.append(norm)
            continue
        if norm.startswith("vendor/phpmailer/"):
            seen.add(norm)
            selected.append(norm)
            continue
        if norm == "uploads/driver_photos/.htaccess":
            seen.add(norm)
            selected.append(norm)
            continue

    return sorted(selected)


def main() -> int:
    parser = argparse.ArgumentParser(description="List bakery deploy files for SFTP upload")
    parser.add_argument("--bakery-root", required=True, help="Path to bakery/ directory")
    parser.add_argument(
        "--repo-root",
        help="Git repo root (default: parent of bakery-root)",
    )
    parser.add_argument(
        "--all",
        action="store_true",
        help="List every deployable file",
    )
    parser.add_argument(
        "--git-from",
        help="Git ref (exclusive start) for changed-file mode",
    )
    parser.add_argument(
        "--git-to",
        default="HEAD",
        help="Git ref (inclusive end) for changed-file mode (default: HEAD)",
    )
    parser.add_argument(
        "--include-extra-root",
        action="store_true",
        help="With --all, also include non-manifest root web files",
    )
    args = parser.parse_args()

    bakery_root = Path(args.bakery_root).resolve()
    if not bakery_root.is_dir():
        print(f"Bakery root not found: {bakery_root}", file=sys.stderr)
        return 1

    if args.all and (args.git_from or args.git_to != "HEAD"):
        print("Use either --all or --git-from/--git-to, not both.", file=sys.stderr)
        return 2

    if args.all:
        files = list_all_deploy_files(bakery_root, include_extra_root=args.include_extra_root)
    elif args.git_from:
        repo_root = Path(args.repo_root).resolve() if args.repo_root else bakery_root.parent
        changed_repo = git_changed_paths(repo_root, args.git_from, args.git_to)
        bakery_rels = []
        for repo_path in changed_repo:
            rel = bakery_rel_from_repo_path(repo_path)
            if rel is not None:
                bakery_rels.append(rel)
        files = filter_deployable(bakery_rels, bakery_root)
    else:
        print("Specify --all or --git-from <ref>.", file=sys.stderr)
        return 2

    for rel in files:
        print(rel)
    print(f"# {len(files)} file(s)", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
