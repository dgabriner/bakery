---
description: Close the current Homebase session with the eight-field handoff
agent: build
---

Prepare and submit an Agent Homebase handoff for the CURRENT mission.

Rules: exactly eight fields, each starting with its line number (1. through 8.) on a single flattened line; submit via `php scripts/agent_homebase.php handoff --agent=<canonical-slug> --files=<comma list> --summary='...' --json`; then verify `handoff_score.complete` is true and say so explicitly.

Fields: 1 what was investigated 2 decisions and why 3 explicit files changed 4 user-visible behavior by role/screen 5 business invariants preserved 6 tests/checks with exact results 7 unresolved questions and bug reconciliation 8 recommendations for the next agent.

$ARGUMENTS
