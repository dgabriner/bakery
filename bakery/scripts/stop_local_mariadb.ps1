# Stop user-process mysqld started for local Sour Flour OS (no admin)
$procs = Get-Process mysqld -ErrorAction SilentlyContinue
if (-not $procs) {
    Write-Host "No mysqld process found"
    exit 0
}
$procs | ForEach-Object {
    Write-Host "Stopping mysqld PID $($_.Id)"
    Stop-Process -Id $_.Id -Force
}
Write-Host "Done"
