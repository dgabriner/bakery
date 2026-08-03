# Share local bakery server to your phone WITHOUT admin / firewall changes.
# Uses a public HTTPS tunnel to localhost:8080 (works on guest Wi-Fi too).
#
# Prerequisites:
#   1. Server already running on port 8080 (run start_local_server.ps1 in another window)
#   2. Node.js OR cloudflared (scoop install cloudflared — no admin)
#
$ErrorActionPreference = "Stop"

function Test-Port8080 {
    $listening = netstat -an 2>$null | Select-String "LISTENING" | Select-String ":8080"
    return [bool]$listening
}

if (-not (Test-Port8080)) {
    Write-Host "Nothing is listening on port 8080."
    Write-Host "Start the server first in another PowerShell window:"
    Write-Host "  cd $((Split-Path $PSScriptRoot -Parent))"
    Write-Host "  .\scripts\start_local_server.ps1"
    Write-Host ""
    exit 1
}

Write-Host "Port 8080 is up. Starting tunnel (no admin needed)..."
Write-Host ""

# Prefer cloudflared (user-scoop install, no admin)
$cloudflared = Get-Command cloudflared.exe -ErrorAction SilentlyContinue
if ($cloudflared) {
    Write-Host "Using Cloudflare Tunnel (cloudflared)."
    Write-Host "Copy the https://....trycloudflare.com URL to your phone browser."
    Write-Host "Append /login.php if the root page is not the login."
    Write-Host ""
    & $cloudflared.Source tunnel --url http://127.0.0.1:8080
    exit $LASTEXITCODE
}

# Fallback: localtunnel via npx (needs Node.js)
$node = Get-Command node.exe -ErrorAction SilentlyContinue
if ($node) {
    Write-Host "Using localtunnel (npx). First run may download packages."
    Write-Host "Open the printed URL on your phone + /login.php"
    Write-Host "If loca.lt asks for a password, enter your PC public IP (see https://ifconfig.me on the PC)."
    Write-Host ""
    & npx --yes localtunnel --port 8080
    exit $LASTEXITCODE
}

Write-Host "Install a tunnel tool (no admin required with Scoop):"
Write-Host ""
Write-Host "  Option A — cloudflared (recommended):"
Write-Host "    scoop install cloudflared"
Write-Host "    .\scripts\share_server_on_phone.ps1"
Write-Host ""
Write-Host "  Option B — Node.js + localtunnel:"
Write-Host "    Install Node from https://nodejs.org"
Write-Host "    .\scripts\share_server_on_phone.ps1"
Write-Host ""
Write-Host "  Option C — firewall popup (no install):"
Write-Host "    Stop PHP, run start_local_server.ps1 again."
Write-Host "    When Windows asks to allow php.exe, check BOTH Private and Public, click Allow."
Write-Host ""
Write-Host "  Option D — set Wi-Fi to Private (Settings > Wi-Fi > your network > Private),"
Write-Host "    then retry http://YOUR_PC_IP:8080/login.php on the phone."
Write-Host ""
exit 1
