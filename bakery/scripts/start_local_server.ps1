# Start PHP dev server reachable from phone on same Wi-Fi (no admin required).
$ErrorActionPreference = "Stop"

$php = "C:\php\php.exe"
if (-not (Test-Path $php)) {
    $phpCmd = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($phpCmd) { $php = $phpCmd.Source }
}
if (-not (Test-Path $php)) {
    Write-Error "php.exe not found. Install PHP or set path to C:\php\php.exe"
}

$bakery = Split-Path $PSScriptRoot -Parent
$root = Split-Path $bakery -Parent
if (-not (Test-Path (Join-Path $bakery "login.php"))) {
    Write-Error "Could not find bakery/login.php"
}

$ip = (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
    Where-Object { $_.IPAddress -notlike '127.*' -and $_.PrefixOrigin -ne 'WellKnown' } |
    Select-Object -First 1).IPAddress
if (-not $ip) {
    $ip = "YOUR_LAN_IP"
}

$firewallRule = Get-NetFirewallRule -DisplayName "Bakery PHP Dev Server 8080" -ErrorAction SilentlyContinue
if (-not $firewallRule) {
    Write-Host "Phone on same Wi-Fi but can't connect? (No admin needed:)"
    Write-Host "  1. When Windows asks to allow php.exe -> Allow (Private + Public)"
    Write-Host "  2. Or run: .\scripts\share_server_on_phone.ps1  (tunnel, no firewall)"
    Write-Host ""
}

Write-Host "Starting Bakery Manager on all interfaces (port 8080)..."
Write-Host "Document root: $bakery"
Write-Host ""
Write-Host "  PC:    http://localhost:8080/login.php"
Write-Host "  LAN:   http://${ip}:8080/login.php"
Write-Host "  Phone: http://${ip}:8080/login.php  (same Wi-Fi, not guest network)"
Write-Host ""
Write-Host "Press Ctrl+C to stop."
Write-Host ""

Set-Location $root
& $php -S 0.0.0.0:8080 -t bakery
