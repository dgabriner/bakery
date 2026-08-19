param([switch]$DryRun, [switch]$Uninstall)

$ErrorActionPreference = "Stop"
$bakeryRoot = Split-Path $PSScriptRoot -Parent
$powerShell = (Get-Command powershell.exe -ErrorAction Stop).Source
$taskPrefix = "SourFlour-"
$taskUser = if ($env:USERDOMAIN) { "$env:USERDOMAIN\$env:USERNAME" } else { $env:USERNAME }

$tasks = @(
    [ordered]@{ Name="${taskPrefix}NightlyDataCycle"; Script="run_nightly_data_cycle.ps1"; Triggers=@(
        (New-ScheduledTaskTrigger -Daily -At "2:00 AM"),
        (New-ScheduledTaskTrigger -AtLogOn -User $taskUser)
    ) },
    [ordered]@{ Name="${taskPrefix}WeeklyBackup"; Script="run_weekly_backup.ps1"; Triggers=@(
        (New-ScheduledTaskTrigger -Weekly -DaysOfWeek Sunday -At "3:00 AM")
    ) },
    [ordered]@{ Name="${taskPrefix}MonthlyRestoreDrill"; Script="run_restore_drill.ps1"; Triggers=@(
        (New-ScheduledTaskTrigger -Weekly -DaysOfWeek Sunday -At "4:00 AM")
    ) }
)

foreach ($task in $tasks) {
    if ($Uninstall) {
        if ($DryRun) { Write-Host "DRY-RUN: unregister $($task.Name)"; continue }
        Unregister-ScheduledTask -TaskName $task.Name -Confirm:$false -ErrorAction SilentlyContinue
        continue
    }
    $scriptPath = Join-Path $PSScriptRoot $task.Script
    $arguments = "-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$scriptPath`""
    $action = New-ScheduledTaskAction -Execute $powerShell -Argument $arguments -WorkingDirectory $bakeryRoot
    $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 3)
    if ($DryRun) {
        Write-Host "DRY-RUN: register $($task.Name) -> $($task.Script)"
        continue
    }
    Register-ScheduledTask -TaskName $task.Name -Action $action -Trigger $task.Triggers -Settings $settings `
        -Description "Sour Flour OS protected data workflow. Logs are under bakery/storage/operations." `
        -User $taskUser -RunLevel Limited -Force | Out-Null
    Write-Host "Installed: $($task.Name)"
}
