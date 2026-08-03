# Allow inbound TCP 8080 (run once as Administrator).
# Home Wi-Fi is often "Public" on Windows — allow both Private and Public profiles.
#Requires -RunAsAdministrator
$ErrorActionPreference = "Stop"

$ruleName = "Bakery PHP Dev Server 8080"
$existing = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
if ($existing) {
    Set-NetFirewallRule -DisplayName $ruleName -Enabled True -Profile Private, Public
    Write-Host "Updated firewall rule: $ruleName (enabled, Private + Public)"
    exit 0
}

New-NetFirewallRule `
    -DisplayName $ruleName `
    -Direction Inbound `
    -Protocol TCP `
    -LocalPort 8080 `
    -Action Allow `
    -Profile Private, Public

Write-Host "Added firewall rule: $ruleName (TCP 8080, Private + Public networks)"
Write-Host "Try on your phone: http://YOUR_PC_IP:8080/login.php"
