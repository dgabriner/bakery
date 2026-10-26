#!/usr/bin/env python3
"""Mocked SFTP login preflight cases. No database and no network."""
from __future__ import annotations

import importlib.util
import io
import logging
import os
import socket
import sys
import types
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path

SECRET = "Zz9uniqueSECRETtoken"
ROOT = Path(sys.argv[1]).resolve()
FAILURES = 0


def block_network() -> None:
    def blocked(*_args, **_kwargs):
        raise AssertionError("real network is forbidden")

    socket.create_connection = blocked
    socket.getaddrinfo = blocked
    socket.socket.connect = blocked  # type: ignore[method-assign]


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Cannot load {path}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def check(ok: bool, label: str) -> None:
    global FAILURES
    print(("PASS  " if ok else "FAIL  ") + label)
    if not ok:
        FAILURES += 1


def install_paramiko(connect):
    class AuthenticationException(Exception):
        pass

    class BadAuthenticationType(AuthenticationException):
        def __init__(self, explanation, types):
            super().__init__(explanation)
            self.explanation = explanation
            self.allowed_types = types

    class AutoAddPolicy:
        pass

    class SFTP:
        def __init__(self):
            self.listed = []
            self.closed = False
            self.puts = 0

        def listdir(self, path):
            self.listed.append(path)
            return []

        def close(self):
            self.closed = True

        def put(self, *_args, **_kwargs):
            self.puts += 1
            raise AssertionError("SFTP put ran during preflight")

    class Transport:
        def __init__(self, *_args, **_kwargs):
            self.none_users = []
            self.password_args = []

        def auth_none(self, username):
            self.none_users.append(username)
            raise BadAuthenticationType("none rejected", ["password", "publickey"])

        def auth_password(self, username, password, event=None, fallback=True):
            self.password_args.append((username, password, event, fallback))
            return []

    class SSHClient:
        created = 0
        last = None

        def __init__(self):
            type(self).created += 1
            type(self).last = self
            self.sftp = SFTP()
            self.transport = Transport()
            self.kwargs = None
            self.closed = False
            self.opened = 0

        def set_missing_host_key_policy(self, policy):
            self.policy = policy

        def connect(self, **kwargs):
            self.kwargs = kwargs
            factory = kwargs.get("transport_factory")
            if factory is not None:
                self.transport = factory(object())
                self.transport.auth_password(kwargs.get("username"), kwargs.get("password"))
            connect(self, kwargs)

        def open_sftp(self):
            self.opened += 1
            return self.sftp

        def get_transport(self):
            return self.transport

        def close(self):
            self.closed = True

        def exec_command(self, command, timeout=None):
            class Channel:
                def recv_exit_status(self):
                    return 0

            class Stream:
                def __init__(self):
                    self.channel = Channel()

                def read(self):
                    return b""

            return None, Stream(), Stream()

    module = types.ModuleType("paramiko")
    module.SSHClient = SSHClient
    module.AutoAddPolicy = AutoAddPolicy
    module.AuthenticationException = AuthenticationException
    module.BadAuthenticationType = BadAuthenticationType
    module.Transport = Transport
    module.SFTPClient = SFTP
    sys.modules["paramiko"] = module
    return module


def set_env(password: str, remote_root: str = "staging-files/") -> None:
    os.environ["DB_NAME"] = "bakerysf_test"
    os.environ["SFTP_HOST"] = "preflight.invalid"
    os.environ["SFTP_USER"] = "preflight-user"
    os.environ["SFTP_PASSWORD"] = password
    os.environ["SFTP_REMOTE_ROOT"] = remote_root
    os.environ["SFTP_TARGET"] = ""


def capture(fn):
    out = io.StringIO()
    err = io.StringIO()
    logs: list[str] = []

    class Grab(logging.Handler):
        def emit(self, record):
            logs.append(record.getMessage())

    handler = Grab()
    root = logging.getLogger()
    root.addHandler(handler)
    try:
        with redirect_stdout(out), redirect_stderr(err):
            code = fn()
    finally:
        root.removeHandler(handler)
    return code, out.getvalue(), err.getvalue(), "\n".join(logs)


def reset_upload(upload) -> None:
    upload.paramiko = None


def test_successful_preflight(stage, upload) -> None:
    def connect(_client, _kwargs):
        return None

    fake = install_paramiko(connect)
    reset_upload(upload)
    set_env(SECRET)
    code, out, err, logs = capture(lambda: upload.sftp_login_preflight(diagnose=False))
    client = fake.SSHClient.last
    kwargs = client.kwargs or {}
    text = out + err + logs
    check(code == 0, "successful preflight exits 0")
    check(kwargs.get("hostname") == "preflight.invalid", "preflight uses SFTP_HOST")
    check(kwargs.get("username") == "preflight-user", "preflight uses SFTP_USER")
    check(kwargs.get("password") == SECRET, "preflight uses the stripped password")
    check(kwargs.get("timeout") == 45, "preflight uses the existing timeout")
    check(kwargs.get("allow_agent") is False, "preflight disables the SSH agent")
    check(kwargs.get("look_for_keys") is False, "preflight does not look for keys")
    check("transport_factory" not in kwargs, "plain preflight uses the normal connect path")
    check(client.sftp.listed == ["staging-files"], "preflight lists the remote root")
    check(client.sftp.closed and client.closed, "preflight closes the SFTP session")
    check(client.transport.none_users == [], "plain preflight does not probe auth methods")
    check(SECRET not in text and "Zz9uniqueSECRET" not in text, "successful preflight does not print the password")


def test_success_reaches_build(stage, upload) -> None:
    def connect(_client, _kwargs):
        return None

    install_paramiko(connect)
    reset_upload(upload)
    set_env(SECRET)
    calls = {"build": 0}

    def build(_args, _live_only):
        calls["build"] += 1
        return 7

    stage.stage_after_preflight = build
    sys.argv = ["cloud_agent_stage.py", "--queue-live"]
    code, _out, _err, _logs = capture(stage.main)
    check(code == 7, "successful preflight continues into the build")
    check(calls["build"] == 1, "successful preflight runs the build once")


def test_failed_auth_skips_build(stage, upload) -> None:
    def connect(_client, _kwargs):
        raise sys.modules["paramiko"].AuthenticationException("Authentication failed.")

    for label, argv in (
        ("--files", ["cloud_agent_stage.py", "--files", "index.php"]),
        ("--queue-live", ["cloud_agent_stage.py", "--queue-live", "--files-live"]),
    ):
        fake = install_paramiko(connect)
        reset_upload(upload)
        set_env(SECRET)
        calls = {"build": 0}

        def build(_args, _live_only, calls=calls):
            calls["build"] += 1
            return 0

        stage.stage_after_preflight = build
        sys.argv = argv
        code, out, err, logs = capture(stage.main)
        text = out + err + logs
        client = fake.SSHClient.last
        check(code != 0, f"failed auth on {label} exits non-zero")
        check(calls["build"] == 0, f"failed auth on {label} exits before the build")
        check(client is not None and client.opened == 0, f"failed auth on {label} does not open a transfer")
        check("before any build" in err, f"failed auth on {label} explains that the build was skipped")
        check(SECRET not in text and "Zz9uniqueSECRET" not in text, f"failed auth on {label} does not print the password")


def test_diagnose_hides_password_and_reports_whitespace(stage, upload) -> None:
    raw = " \t" + SECRET + "\n"

    def connect(_client, _kwargs):
        return None

    fake = install_paramiko(connect)
    reset_upload(upload)
    set_env(raw)
    sys.argv = ["cloud_agent_stage.py", "--diagnose", "--files", "index.php", "--queue-live"]
    calls = {"build": 0}
    stage.stage_after_preflight = lambda *_args, **_kwargs: calls.__setitem__("build", calls["build"] + 1)
    code, out, err, logs = capture(stage.main)
    text = out + err + logs
    client = fake.SSHClient.last
    kwargs = client.kwargs or {}
    check(code == 0, "diagnose exits 0 when login works")
    check(calls["build"] == 0, "diagnose does not continue into the build")
    check(SECRET not in text and "Zz9uniqueSECRET" not in text, "diagnose output does not contain the password")
    check(f"password_length={len(raw)}" in out, "diagnose prints the raw password length")
    check("password_leading_whitespace=yes" in out, "diagnose detects leading whitespace")
    check("password_trailing_whitespace=yes" in out, "diagnose detects trailing whitespace")
    check("password_newline=yes" in out, "diagnose detects a newline")
    check("auth_methods=password,publickey" in out, "diagnose prints the server auth methods")
    check("host=preflight.invalid" in out, "diagnose prints the host")
    check("user=preflight-user" in out, "diagnose prints the user")
    check("remote_root=staging-files" in out, "diagnose prints the remote root")
    check("target=(unset)" in out, "diagnose prints an unset target")
    check(kwargs.get("password") == SECRET, "diagnose connects with the stripped password")
    check("\n" not in (kwargs.get("password") or ""), "diagnose does not send the trailing newline")
    check(client.transport.none_users == ["preflight-user"], "diagnose learns auth methods from auth_none")
    check(client.sftp.puts == 0, "diagnose does not upload")

    reset_upload(upload)
    install_paramiko(connect)
    set_env(SECRET)
    code, out, _err, logs = capture(lambda: upload.sftp_login_preflight(diagnose=True))
    text = out + _err + logs
    check(code == 0, "uploader diagnose exits 0")
    check("password_leading_whitespace=no" in out, "clean password has no leading whitespace")
    check("password_trailing_whitespace=no" in out, "clean password has no trailing whitespace")
    check("password_newline=no" in out, "clean password has no newline")
    check(SECRET not in text and "Zz9uniqueSECRET" not in text, "uploader diagnose does not contain the password")

    def fail_connect(_client, _kwargs):
        raise sys.modules["paramiko"].AuthenticationException("Authentication failed.")

    fake = install_paramiko(fail_connect)
    reset_upload(upload)
    set_env(raw)
    code, out, err, logs = capture(lambda: upload.sftp_login_preflight(diagnose=True))
    text = out + err + logs
    check(code != 0, "diagnose exits non-zero when login fails")
    check("password_newline=yes" in out, "failed diagnose still reports a newline")
    check("auth_methods=password,publickey" in out, "failed diagnose still reports auth methods")
    check(SECRET not in text and "Zz9uniqueSECRET" not in text, "failed diagnose does not contain the password")
    check(fake.SSHClient.last.opened == 0, "failed diagnose does not open a transfer")


def test_uploader_failure_does_not_transfer(upload) -> None:
    def connect(_client, _kwargs):
        raise sys.modules["paramiko"].AuthenticationException("Authentication failed.")

    fake = install_paramiko(connect)
    reset_upload(upload)
    set_env(SECRET)
    local = ROOT / "index.php"
    sys.argv = [
        "sftp_upload.py",
        "--local-root",
        str(ROOT),
        "--from-file",
        str(local),
        "--to-name",
        "index.php",
    ]
    code, _out, err, _logs = capture(upload.main)
    client = fake.SSHClient.last
    check(code != 0, "uploader failed auth exits non-zero")
    check("before any build" in err, "uploader failed auth exits before the upload")
    check(client.opened == 0 and client.sftp.puts == 0, "uploader failed auth does not transfer files")


def test_offline_flags_do_not_connect(upload) -> None:
    def connect(_client, _kwargs):
        raise AssertionError("offline flag opened a connection")

    fake = install_paramiko(connect)
    reset_upload(upload)
    set_env("dummy-password")
    sys.argv = ["sftp_upload.py", "--local-root", str(ROOT), "--check-target"]
    code, _out, _err, _logs = capture(upload.main)
    check(code == 0 and fake.SSHClient.created == 0, "check-target does not open SFTP")

    fake = install_paramiko(connect)
    reset_upload(upload)
    list_path = Path("/tmp/bakery_sftp_preflight_files.txt")
    list_path.write_text("index.php\n", encoding="utf-8")
    sys.argv = ["sftp_upload.py", "--local-root", str(ROOT), "--dry-run", "--list", str(list_path)]
    code, _out, _err, _logs = capture(upload.main)
    check(code == 0 and fake.SSHClient.created == 0, "dry-run does not open SFTP")


def test_ssh_uses_stripped_password(stage, upload) -> None:
    raw = SECRET + "\n"

    def connect(_client, _kwargs):
        return None

    fake = install_paramiko(connect)
    reset_upload(upload)
    set_env(raw)
    code, out, err = stage.ssh_capture("true")
    kwargs = fake.SSHClient.last.kwargs or {}
    check(code == 0, "ssh capture returns the remote status")
    check(kwargs.get("password") == SECRET, "queue SSH uses the same stripped password")
    check(kwargs.get("allow_agent") is False and kwargs.get("look_for_keys") is False, "queue SSH disables agent keys")
    check(SECRET not in out and SECRET not in err, "ssh capture does not print the password")


def test_live_root_never_connects(upload) -> None:
    def connect(_client, _kwargs):
        raise AssertionError("refused target still connected")

    fake = install_paramiko(connect)
    reset_upload(upload)
    set_env(SECRET, "not-a-live-root")
    os.environ["SFTP_TARGET"] = "dreamhost-live"
    try:
        capture(lambda: upload.sftp_login_preflight(diagnose=False))
        refused = True
    except SystemExit:
        refused = True
    check(refused and fake.SSHClient.created == 0, "a refused live target does not connect")


def main() -> int:
    os.environ["DB_NAME"] = "bakerysf_test"
    if os.environ["DB_NAME"] != "bakerysf_test":
        print("FAIL  tests must use bakerysf_test")
        return 1
    block_network()
    stage = load_module("cloud_agent_stage_under_test", ROOT / "scripts" / "cloud_agent_stage.py")
    upload = stage.sftp_upload
    test_successful_preflight(stage, upload)
    test_success_reaches_build(stage, upload)
    test_failed_auth_skips_build(stage, upload)
    test_diagnose_hides_password_and_reports_whitespace(stage, upload)
    test_uploader_failure_does_not_transfer(upload)
    test_offline_flags_do_not_connect(upload)
    test_ssh_uses_stripped_password(stage, upload)
    test_live_root_never_connects(upload)
    if FAILURES:
        print(f"{FAILURES} SFTP preflight check(s) failed.")
        return 1
    print("OK sftp preflight")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
