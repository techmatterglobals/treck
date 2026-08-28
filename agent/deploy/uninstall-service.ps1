#Requires -Version 5.1
#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Stops and removes the Treck Agent Windows Service.

.DESCRIPTION
    Stops the service (if running), deletes its SCM registration, and optionally
    removes the installed binaries and/or the local data directory (device id,
    encrypted token, offline SQLite queue, logs).

    By default the data directory is preserved so a reinstall keeps the device's
    identity and any not-yet-synced events. Use -PurgeData to wipe it.

.PARAMETER RemoveFiles
    Also delete the install directory (binaries). Default: on.

.PARAMETER PurgeData
    Also delete %ProgramData%\TreckAgent (identity, token, offline queue, logs).

.EXAMPLE
    ./uninstall-service.ps1
    ./uninstall-service.ps1 -PurgeData
#>
[CmdletBinding()]
param(
    [string] $ServiceName = 'TreckAgent',
    [string] $InstallDir = (Join-Path $env:ProgramFiles 'TreckAgent'),
    [bool]   $RemoveFiles = $true,
    [switch] $PurgeData
)

$ErrorActionPreference = 'Stop'

$svc = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if (-not $svc) {
    Write-Host "Service '$ServiceName' is not installed." -ForegroundColor Yellow
}
else {
    if ($svc.Status -ne 'Stopped') {
        Write-Host "Stopping '$ServiceName'." -ForegroundColor Cyan
        Stop-Service -Name $ServiceName -Force -ErrorAction SilentlyContinue
        $svc.WaitForStatus('Stopped', (New-TimeSpan -Seconds 30))
    }
    Write-Host "Removing service '$ServiceName'." -ForegroundColor Cyan
    sc.exe delete $ServiceName | Out-Null
    Start-Sleep -Seconds 2
}

if ($RemoveFiles -and (Test-Path $InstallDir)) {
    Write-Host "Removing install directory '$InstallDir'." -ForegroundColor Cyan
    Remove-Item -Path $InstallDir -Recurse -Force -ErrorAction SilentlyContinue
}

$dataDir = Join-Path $env:ProgramData 'TreckAgent'
if ($PurgeData) {
    if (Test-Path $dataDir) {
        Write-Host "Purging data directory '$dataDir'." -ForegroundColor Yellow
        Remove-Item -Path $dataDir -Recurse -Force -ErrorAction SilentlyContinue
    }
}
else {
    Write-Host "Data directory preserved: $dataDir (use -PurgeData to remove)." -ForegroundColor DarkGray
}

Write-Host "Treck Agent uninstalled." -ForegroundColor Green
