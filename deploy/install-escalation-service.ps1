<#
.SYNOPSIS
    Installs the S.P.O.T.-IT escalation job as an auto-starting Windows service.

.DESCRIPTION
    auth/escalate_detections.php is what moves a detection from 'pending' to
    'potential' at 30 minutes and to 'confirmed_missing' at 60. Its logic was
    correct but nothing ever called it, so in practice every detection sat at
    'pending' forever and the two-stage escalation in the thesis existed only on
    paper. This script schedules it.

    Same NSSM approach as install-service.ps1, for the same reason: no logged-in
    user, no open terminal, restarts itself after a crash or a reboot. NSSM
    supervises processes rather than schedules, so the actual 5-minute loop
    lives in escalation-runner.ps1 and this registers that loop as a service.

    This is the LOCAL / XAMPP answer. On Hostinger, use the control-panel cron
    instead - see md_files/DEPLOYMENT_GUIDE.md. Run exactly one of the two: both
    at once would double every notification.

    NOTE: this file is deliberately pure ASCII, for the same reason as
    install-service.ps1 - PowerShell 5.1 reads .ps1 as ANSI without a BOM.

.PARAMETER IntervalSeconds
    Seconds between escalation passes. Default 300 (5 minutes).

.PARAMETER PhpExe
    Path to php.exe. Defaults to whatever is on PATH (XAMPP: C:\xampp\php\php.exe).

.EXAMPLE
    .\install-escalation-service.ps1

.EXAMPLE
    .\install-escalation-service.ps1 -PhpExe 'C:\xampp\php\php.exe' -IntervalSeconds 300

.NOTES
    Must be run from an ELEVATED PowerShell (Run as Administrator).
    Requires NSSM on PATH: https://nssm.cc/download  (or "choco install nssm")

    MySQL must be running for the job to do anything. If XAMPP's MySQL is
    started by hand, the service will log connection errors until it is up -
    which is harmless, it simply retries on the next tick.
#>

[CmdletBinding()]
param(
    [int]    $IntervalSeconds = 300,
    [string] $ServiceName     = 'SpotItEscalation',
    [string] $PhpExe          = ''
)

$ErrorActionPreference = 'Stop'

# ---- Preconditions ----------------------------------------------------------
$identity  = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Error "This script must be run from an elevated PowerShell (Run as Administrator)."
}

if (-not (Get-Command nssm -ErrorAction SilentlyContinue)) {
    Write-Error "NSSM was not found on PATH. Install with 'choco install nssm', or download nssm.exe from https://nssm.cc/download and put it on PATH."
}

if (-not $PhpExe) {
    $php = Get-Command php -ErrorAction SilentlyContinue
    if (-not $php) {
        Write-Error "php.exe was not found on PATH. Pass -PhpExe 'C:\xampp\php\php.exe'."
    }
    $PhpExe = $php.Source
}
if (-not (Test-Path $PhpExe)) {
    Write-Error "php.exe not found at '$PhpExe'."
}

# Repo root is this script's parent directory.
$repoRoot = Split-Path -Parent $PSScriptRoot
$runner   = Join-Path $PSScriptRoot 'escalation-runner.ps1'
$job      = Join-Path $repoRoot 'auth\escalate_detections.php'

if (-not (Test-Path $runner)) {
    Write-Error "Could not find '$runner'. Keep this script inside the repo's deploy/ folder."
}
if (-not (Test-Path $job)) {
    Write-Error "Could not find '$job'. Keep this script inside the repo's deploy/ folder."
}

$logDir = Join-Path $repoRoot 'logs'
if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Path $logDir | Out-Null }

# Sanity check: run the job once, now, before committing to a service. A
# misconfigured DB shows up here as readable output rather than as a silent
# service that never escalates anything.
Write-Host ""
Write-Host "Test run before installing..." -ForegroundColor Cyan
& $PhpExe $job
if ($LASTEXITCODE -ne 0) {
    Write-Warning "The job exited with code $LASTEXITCODE. Installing anyway, but check config/env.local.php and that MySQL is running."
}

Write-Host ""
Write-Host "S.P.O.T.-IT escalation service" -ForegroundColor Green
Write-Host "  service name : $ServiceName"
Write-Host "  php          : $PhpExe"
Write-Host "  runner       : $runner"
Write-Host "  job          : $job"
Write-Host "  interval     : $IntervalSeconds second(s)"
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
# -ExecutionPolicy Bypass because the service account does not inherit the
# interactive user's policy, and an unsigned local script would otherwise be
# refused at startup with nothing but an exit code to show for it.
$powershellExe = (Get-Command powershell).Source
$appArgs = '-NoProfile -ExecutionPolicy Bypass -File "' + $runner + '"' +
           ' -IntervalSeconds ' + $IntervalSeconds +
           ' -PhpExe "' + $PhpExe + '"'

& nssm install $ServiceName $powershellExe $appArgs
& nssm set $ServiceName AppDirectory $repoRoot
& nssm set $ServiceName DisplayName  "S.P.O.T.-IT Escalation Job"
& nssm set $ServiceName Description  "Escalates pending detections to potential (30 min) and confirmed_missing (60 min) every $IntervalSeconds seconds."
& nssm set $ServiceName Start        SERVICE_AUTO_START

# ---- Logging ----------------------------------------------------------------
# Every pass prints a timestamped summary, so this file doubles as the evidence
# that escalation is actually running.
& nssm set $ServiceName AppStdout       (Join-Path $logDir 'escalation.log')
& nssm set $ServiceName AppStderr       (Join-Path $logDir 'escalation.err.log')
& nssm set $ServiceName AppRotateFiles  1
& nssm set $ServiceName AppRotateOnline 1
& nssm set $ServiceName AppRotateBytes  10485760

# ---- Crash recovery ---------------------------------------------------------
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
    Write-Host "Confirm it:" -ForegroundColor Cyan
    Write-Host "  nssm status $ServiceName"
    Write-Host "  Get-Content '$logDir\escalation.log' -Wait -Tail 20"
    Write-Host ""
    Write-Host "A line reading 'Escalation run complete' every $IntervalSeconds seconds means it is working."
    Write-Host "End-to-end test (ages a detection past the 30 min threshold):"
    Write-Host "  see md_files/TESTING_GUIDE.md, 'Auto-escalation'"
} else {
    Write-Warning "Service did not reach Running state. Check $logDir\escalation.err.log"
    Write-Host "Common causes: wrong php.exe path, MySQL not running, bad config/env.local.php."
}

Write-Host ""
Write-Host "Manage it later with:" -ForegroundColor DarkGray
Write-Host "  nssm status  $ServiceName"
Write-Host "  nssm restart $ServiceName"
Write-Host "  nssm stop    $ServiceName"
Write-Host "  .\uninstall-escalation-service.ps1"
Write-Host ""
