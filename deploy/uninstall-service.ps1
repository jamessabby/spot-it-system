<#
.SYNOPSIS
    Stops and removes the S.P.O.T.-IT detector Windows service.

.DESCRIPTION
    Removes only the service registration. main.py, the ROI files, baselines and
    logs are all left alone, so re-running install-service.ps1 restores exactly
    the previous behaviour.

    Use this before moving the mini-PC to another room, changing the API key, or
    handing the machine back at the end of the thesis.

.EXAMPLE
    .\uninstall-service.ps1

.NOTES
    Must be run from an ELEVATED PowerShell (Run as Administrator).
#>

[CmdletBinding()]
param(
    [string] $ServiceName = 'SpotItDetector'
)

$ErrorActionPreference = 'Stop'

$isAdmin = ([Security.Principal.WindowsPrincipal] `
    [Security.Principal.WindowsIdentity]::GetCurrent()
).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isAdmin) {
    Write-Error "This script must be run from an elevated PowerShell (Run as Administrator)."
}

$svc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if (-not $svc) {
    Write-Host "Service '$ServiceName' is not installed - nothing to do." -ForegroundColor Yellow
    return
}

if (-not (Get-Command nssm -ErrorAction SilentlyContinue)) {
    Write-Error "NSSM not found on PATH; it is needed to remove the service cleanly."
}

if ($svc.Status -ne 'Stopped') {
    Write-Host "Stopping $ServiceName..." -ForegroundColor Cyan
    & nssm stop $ServiceName | Out-Null
    Start-Sleep -Seconds 2
}

& nssm remove $ServiceName confirm | Out-Null
Start-Sleep -Seconds 1

if (Get-Service -Name $ServiceName -ErrorAction SilentlyContinue) {
    Write-Warning "Service still present. A reboot may be required to finish removal."
} else {
    Write-Host "Service '$ServiceName' removed." -ForegroundColor Green
    Write-Host "Detection has stopped for this room - the dashboard will show DETECTOR OFFLINE." -ForegroundColor Yellow
}
