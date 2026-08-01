<#
.SYNOPSIS
    Installs S.P.O.T.-IT's detector as an auto-starting Windows service.

.DESCRIPTION
    Run this ONCE on the mini-PC that lives in the lab. After it completes, the
    detector starts automatically at boot, restarts itself if it crashes, and
    needs no logged-in user and no open terminal - which is the whole point:
    nobody should have to remember to run "python main.py" before a class.

    Uses NSSM (the Non-Sucking Service Manager) rather than Docker. On Windows,
    Docker Desktop needs a logged-in user session and adds a virtual network
    layer between the container and the camera - both are liabilities for an
    unattended box. NSSM is a single executable that wraps a normal process.

    NOTE: this file is deliberately pure ASCII. Windows PowerShell 5.1 reads
    .ps1 files as ANSI unless they carry a UTF-8 BOM, so decorative Unicode in
    comments silently corrupts the script and produces baffling parse errors.

.PARAMETER ApiUrl
    Full URL of ingest_detection.php on the hosted site. The detector derives the
    other endpoints from it, so this one value points it at the right server.

.PARAMETER ApiKey
    Must match SPOTIT_DETECTION_KEY in the site's config/env.local.php.

.PARAMETER RoomId
    Which room this machine watches, e.g. ROOM_1.

.EXAMPLE
    .\install-service.ps1 -ApiUrl "https://spotit.example.com/auth/ingest_detection.php" -ApiKey "d41d8c..." -RoomId ROOM_1

.NOTES
    Must be run from an ELEVATED PowerShell (Run as Administrator).
    Requires NSSM on PATH: https://nssm.cc/download  (or "choco install nssm")
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)] [string] $ApiUrl,
    [Parameter(Mandatory = $true)] [string] $ApiKey,
    [Parameter(Mandatory = $true)] [string] $RoomId,
    [string] $ServiceName = 'SpotItDetector',
    [string] $PythonExe   = ''
)

$ErrorActionPreference = 'Stop'

# ---- Preconditions ----------------------------------------------------------
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Error "This script must be run from an elevated PowerShell (Run as Administrator)."
}

if (-not (Get-Command nssm -ErrorAction SilentlyContinue)) {
    Write-Error "NSSM was not found on PATH. Install with 'choco install nssm', or download nssm.exe from https://nssm.cc/download and put it on PATH."
}

if (-not $PythonExe) {
    $py = Get-Command python -ErrorAction SilentlyContinue
    if (-not $py) {
        Write-Error "Python was not found on PATH. Pass -PythonExe 'C:\path\to\python.exe'."
    }
    $PythonExe = $py.Source
}

# Repo root is this script's parent directory.
$repoRoot = Split-Path -Parent $PSScriptRoot
$mainPy   = Join-Path $repoRoot 'main.py'
if (-not (Test-Path $mainPy)) {
    Write-Error "Could not find main.py at '$mainPy'. Keep this script inside the repo's deploy/ folder."
}

$logDir = Join-Path $repoRoot 'logs'
if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Path $logDir | Out-Null }

$RoomId = $RoomId.ToUpper()

Write-Host ""
Write-Host "S.P.O.T.-IT detector service" -ForegroundColor Green
Write-Host "  service name : $ServiceName"
Write-Host "  python       : $PythonExe"
Write-Host "  script       : $mainPy"
Write-Host "  room         : $RoomId"
Write-Host "  server       : $ApiUrl"
Write-Host "  logs         : $logDir"
Write-Host ""

# ---- Replace any previous installation --------------------------------------
$existing = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if ($existing) {
    Write-Host "Existing service found - removing it first..." -ForegroundColor Yellow
    if ($existing.Status -ne 'Stopped') { & nssm stop $ServiceName | Out-Null }
    & nssm remove $ServiceName confirm | Out-Null
    Start-Sleep -Seconds 2
}

# ---- Install ----------------------------------------------------------------
$appArgs = '"' + $mainPy + '" ' + $RoomId
& nssm install $ServiceName $PythonExe $appArgs
& nssm set $ServiceName AppDirectory $repoRoot
& nssm set $ServiceName DisplayName  "S.P.O.T.-IT Detector ($RoomId)"
& nssm set $ServiceName Description  "Monitors $RoomId via RTSP and reports item deviations to the S.P.O.T.-IT server."
& nssm set $ServiceName Start        SERVICE_AUTO_START

# ---- Environment ------------------------------------------------------------
# SPOTIT_DEPLOY=remote is what switches main.py from reading config off local
# disk to fetching it over HTTP. Without it the detector runs happily and
# ignores every change made in the dashboard.
#
# SPOTIT_GUI is deliberately NOT set: a service account has no desktop session,
# so cv2.imshow would throw on the first frame.
#
# PYTHONUNBUFFERED=1 matters more than it looks - without it stdout buffers and
# the log file stays empty for minutes, which is miserable when diagnosing a
# machine you cannot see.
$envLines = @(
    "API_URL=$ApiUrl",
    "API_KEY=$ApiKey",
    "SPOTIT_DEPLOY=remote",
    "ROOM_ID=$RoomId",
    "PYTHONUNBUFFERED=1"
)
& nssm set $ServiceName AppEnvironmentExtra ($envLines -join "`r`n")

# ---- Logging ----------------------------------------------------------------
# With no terminal, these files are the only way to see what happened. Rotated
# at 10 MB so an unattended box cannot fill its own disk over a semester.
& nssm set $ServiceName AppStdout       (Join-Path $logDir 'detector.log')
& nssm set $ServiceName AppStderr       (Join-Path $logDir 'detector.err.log')
& nssm set $ServiceName AppRotateFiles  1
& nssm set $ServiceName AppRotateOnline 1
& nssm set $ServiceName AppRotateBytes  10485760

# ---- Crash recovery ---------------------------------------------------------
# Restart 5s after an exit. The throttle stops a permanently broken config (bad
# API key, camera unplugged) from spinning in a tight restart loop.
& nssm set $ServiceName AppExit Default Restart
& nssm set $ServiceName AppRestartDelay 5000
& nssm set $ServiceName AppThrottle 10000

# ---- Start ------------------------------------------------------------------
Write-Host "Starting service..." -ForegroundColor Cyan
& nssm start $ServiceName
Start-Sleep -Seconds 4

$svc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if ($svc -and $svc.Status -eq 'Running') {
    Write-Host ""
    Write-Host "Service is RUNNING." -ForegroundColor Green
    Write-Host ""
    Write-Host "Confirm it end to end:" -ForegroundColor Cyan
    Write-Host "  1. Open Room Cameras for $RoomId on the site."
    Write-Host "  2. The pill top-left should read LIVE with an FPS figure within"
    Write-Host "     about 30 seconds. If it says DETECTOR OFFLINE, the service is"
    Write-Host "     up but cannot reach the server - check the API key and URL."
    Write-Host "  3. Tail the log:"
    Write-Host "     Get-Content '$logDir\detector.log' -Wait -Tail 40"
} else {
    Write-Warning "Service did not reach Running state. Check $logDir\detector.err.log"
    Write-Host "Common causes: wrong Python path, missing packages (pip install -r requirements.txt), camera unreachable."
}

Write-Host ""
Write-Host "Manage it later with:" -ForegroundColor DarkGray
Write-Host "  nssm restart $ServiceName"
Write-Host "  nssm stop    $ServiceName"
Write-Host "  .\uninstall-service.ps1"
Write-Host ""
