# Auto-push to DreamHost

Keeps `bakery.sourflour.org/bake/` nearly mirrored with local deployable files.

## What you need

1. **`.env.sftp`** in the bakery folder (already set up locally)
2. **Python + paramiko** (`py -m pip install paramiko`)
3. **Trusted workspace** in Cursor (Hooks only run when the folder is trusted)
4. **Hooks enabled** — Cursor Settings → Hooks; you should see `afterFileEdit`, `afterTabFileEdit`, `stop`

## Cursor agent / Tab edits

Project hooks in `.cursor/hooks.json` fire when:

| Event | When |
|-------|------|
| `afterFileEdit` | Agent edits a file |
| `afterTabFileEdit` | Tab autocomplete applies an edit |
| `stop` | Agent turn finishes |

They **queue** a push (15–20s debounce), then upload only changed deployable files.

**Manual typing/saving in the editor does not fire Agent hooks.** For that, use the watcher below.

## Edits outside Cursor

When **Live auto-push** is ON in the local UI, a background file watcher starts automatically. It uploads deployable changes from:

- Cursor agent / Tab edits (hooks)
- Manual saves in any editor
- New files created outside Cursor

Turning the toggle **OFF** stops that watcher.

You can still run `watch_push.bat` manually if you want a visible console window.

## Verify it is working

1. Make an agent edit to a `.php` file (or run `watch_push.bat` and save a file)
2. Wait ~20 seconds
3. Check `storage/deploy/auto_push.log` for lines like:
   - `HOOK  fired ...`
   - `QUEUED ...`
   - `PUSH start` / `PUSH done exit=0`

## Disable from the local website

Log in as `danny@sourflour.org` on the local app. In the yellow/red local banner:

- **Live auto-push** toggle — ON keeps hooks/watcher ready to upload; OFF writes `storage/deploy/.auto_push_disabled`
- **Sync to live** — runs `push_sftp.ps1` immediately (works whether auto-push is on or off)

## Disable manually

Create an empty file:

```
storage/deploy/.auto_push_disabled
```

Or use the UI toggle above.

## Still stuck?

- Confirm workspace trust (banner / folder trust)
- Reload Cursor window after changing `hooks.json`
- In Hooks output, check whether `auto-push.cmd` runs or times out
- Run manually: `push.bat` (should still work)
