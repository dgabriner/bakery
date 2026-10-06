# Rebuild the intern PDFs from the HTML in this folder.
$edge = "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe"
if (-not (Test-Path $edge)) {
  $edge = "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe"
}
if (-not (Test-Path $edge)) {
  throw "Microsoft Edge was not found."
}

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$names = @(
  "01-start-here",
  "02-walk-the-day",
  "03-the-screens",
  "04-how-the-code-works"
)

foreach ($name in $names) {
  $html = Join-Path $root "$name.html"
  $pdf = Join-Path $root "$name.pdf"
  $uri = ([Uri]$html).AbsoluteUri
  $profile = Join-Path $env:TEMP ("intern-pdf-" + [guid]::NewGuid().ToString("N"))
  New-Item -ItemType Directory -Path $profile | Out-Null
  & $edge --headless --disable-gpu --no-first-run --no-pdf-header-footer --user-data-dir="$profile" --print-to-pdf="$pdf" $uri
  Start-Sleep -Seconds 1
  Remove-Item -Recurse -Force $profile -ErrorAction SilentlyContinue
  if (-not (Test-Path $pdf)) {
    throw "Edge did not write $pdf"
  }
  $size = (Get-Item $pdf).Length
  if ($size -lt 10000) {
    throw "$pdf is only $size bytes"
  }
  Write-Output "$name.pdf $size"
}
