---
description: Run the Ox controller health check
agent: build
---

Run `php scripts/ox/ox.php doctor` from the bakery workspace root and report each PASS/FAIL line verbatim. If anything fails, diagnose from the failure note only; do not attempt repairs beyond re-running.
