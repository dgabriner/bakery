# MariaDB user-process helpers (no Windows service / no admin)

Requires Scoop MariaDB shims on PATH (`~\scoop\shims`).

## Start

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File bakery\scripts\start_local_mariadb.ps1
```

## Stop

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File bakery\scripts\stop_local_mariadb.ps1
```
