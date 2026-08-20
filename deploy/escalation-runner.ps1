<#
.SYNOPSIS
    Runs the S.P.O.T.-IT escalation job on a fixed interval, forever.

.DESCRIPTION
    auth/escalate_detections.php is a one-shot job: it promotes pending
    detections to 'potential' at 30 minutes and to 'confirmed_missing' at 60,
    then exits. Something has to call it. This script is that something.

    NSSM supervises long-running PROCESSES, not schedules - it has no concept of
    "every 5 minutes". So the loop lives here and NSSM keeps the loop alive.
    That is deliberately not Task Scheduler: the detector is already an NSSM
    service (install-service.ps1), and having both halves of the system managed
    the same way means one place to look when something is not running.

    Each pass is wrapped so that a database being briefly unreachable logs a
    line and waits for the next tick, rather than killing the service and
    handing NSSM a restart loop.

    NOTE: pure ASCII on purpose. Windows PowerShell 5.1 reads .ps1 as ANSI
    unless the file carries a UTF-8 BOM, so decorative Unicode in comments
    corrupts the script and produces baffling parse errors.

.PARAMETER IntervalSeconds
    Seconds between runs. 300 (5 min) is the documented cadence: the escalation
    thresholds are 30 and 60 minutes, so 5 minutes bounds the lateness of an
    escalation at well under a fifth of the shorter one.

.PARAMETER PhpExe
    Path to php.exe. Defaults to whatever is on PATH.

.EXAMPLE
    .\escalation-runner.ps1 -IntervalSeconds 300

.NOTES
    Not meant to be run by hand except for testing - install-escalation-service.ps1
    registers it as a service. Ctrl+C stops it when run interactively.
#>

[CmdletBinding()]
param(
    [int]    $IntervalSeconds = 300,
    [string] $PhpExe = ''
)

$ErrorActionPreference = 'Continue'

if (-not $PhpExe) {
    $php = Get-Command php -ErrorAction SilentlyContinue
    if (-not $php) {
        Write-Error "php.exe was not found on PATH. Pass -PhpExe 'C:\xampp\php\php.exe'."
        exit 1
    }
    $PhpExe = $php.Source
}

# Repo root is this script's parent directory.
$repoRoot = Split-Path -Parent $PSScriptRoot
$script   = Join-Path $repoRoot 'auth\escalate_detections.php'

if (-not (Test-Path $script)) {
    Write-Error "Could not find '$script'. Keep this script inside the repo's deploy/ folder."
    exit 1
}

Write-Host "S.P.O.T.-IT escalation runner"
Write-Host "  php      : $PhpExe"
Write-Host "  job      : $script"
Write-Host "  interval : $IntervalSeconds second(s)"
Write-Host ""

while ($true) {
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    try {
        # CLI invocation, so escalate_detections.php skips the cron-key check
        # entirely - the key only guards the HTTP path used on shared hosting.
        $output = & $PhpExe $script 2>&1
        $exit   = $LASTEXITCODE

        foreach ($line in $output) {
            Write-Host "[$stamp] $line"
        }

        if ($exit -ne 0) {
            Write-Host "[$stamp] WARNING: php exited with code $exit"
        }
    }
    catch {
        # Never rethrow: a transient failure must not take the service down.
        Write-Host "[$stamp] ERROR: $($_.Exception.Message)"
    }

    Start-Sleep -Seconds $IntervalSeconds
}
