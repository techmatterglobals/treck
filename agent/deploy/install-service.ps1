#Requires -Version 5.1
#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Installs (or reinstalls) the Treck Agent as a Windows Service.

.DESCRIPTION
    Optionally publishes a build, copies it into the install directory, creates
    the writable log directory under ProgramData, registers the service with the
    Service Control Manager (automatic startup), selects the .NET environment,
    configures automatic restart on failure, starts the service, and prints its
    status.

    Supply an already-published build via -PublishDir, or pass -Publish to build
    one first (framework-dependent by default; add -SelfContained to bundle the
    runtime).

.PARAMETER Publish
    Build a release (via publish.ps1) before installing. Framework-dependent by
    default (requires the .NET 8 Desktop Runtime on this machine).

.PARAMETER SelfContained
    With -Publish, bundle the .NET runtime so no runtime install is needed
    (larger; downloads the win-x64 runtime packs on this build host).

.PARAMETER PublishDir
    Directory containing the published build (must contain TreckAgent.exe).
    Defaults to agent/publish.

.PARAMETER InstallDir
    Where the service binaries are copied. Default: %ProgramFiles%\TreckAgent.

.PARAMETER Environment
    .NET environment name (selects appsettings.<Env>.json). Default: Production.

.EXAMPLE
    # Build and install in one step:
    ./install-service.ps1 -Publish

.EXAMPLE
    # Install a pre-published build:
    ./install-service.ps1 -PublishDir C:\build\treck-agent
#>
[CmdletBinding()]
param(
    [switch] $Publish,
    [switch] $SelfContained,
    # Defaults are applied after the param() block (see below): $PSScriptRoot is
    # not yet initialized while param() defaults are bound on Windows PowerShell
    # 5.1, so referencing it here yields an empty path.
    [string] $PublishDir,
    [string] $InstallDir,
    [string] $ServiceName = 'TreckAgent',
    [string] $DisplayName = 'Treck Agent',
    [string] $Description = 'Treck employee productivity and PC activity monitoring agent. Captures workstation session and activity telemetry and syncs it to the Treck server.',
    [ValidateSet('Production', 'Staging', 'Development')]
    [string] $Environment = 'Production'
)

$ErrorActionPreference = 'Stop'

# Resolve this script's own directory safely. $PSScriptRoot is reliable in the
# script body on both Windows PowerShell 5.1 and PowerShell 7+ (only param()
# default binding on 5.1 is too early); fall back to $MyInvocation for any edge
# invocation where it is empty.
$scriptDir = $PSScriptRoot
if (-not $scriptDir) {
    $scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
}

# Apply directory defaults now that $scriptDir is known. Leaving a parameter
# unset uses the same default as before; an explicit -PublishDir / -InstallDir
# still takes precedence.
if (-not $PublishDir) {
    $PublishDir = Join-Path $scriptDir '..\publish'
}
if (-not $InstallDir) {
    $InstallDir = Join-Path $env:ProgramFiles 'TreckAgent'
}

# Resolve PublishDir to an absolute, normalized path (whether it is the default,
# an absolute path, or a path relative to the current location).
if (-not [System.IO.Path]::IsPathRooted($PublishDir)) {
    $PublishDir = Join-Path (Get-Location).Path $PublishDir
}
$PublishDir = [System.IO.Path]::GetFullPath($PublishDir)

function Wait-ServiceRemoved {
    param(
        [string] $Name,
        [int]    $TimeoutSeconds = 15
    )
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        if (-not (Get-Service -Name $Name -ErrorAction SilentlyContinue)) {
            return $true
        }
        Start-Sleep -Milliseconds 500
    }
    return (-not (Get-Service -Name $Name -ErrorAction SilentlyContinue))
}

# --- 1. Optionally publish a build ------------------------------------------
if ($Publish) {
    & (Join-Path $scriptDir 'publish.ps1') -OutputDir $PublishDir -SelfContained:$SelfContained
}

$exeSource = Join-Path $PublishDir 'TreckAgent.exe'
if (-not (Test-Path $exeSource)) {
    throw "TreckAgent.exe not found in '$PublishDir'. Publish first (-Publish) or pass -PublishDir."
}

# --- 2. Remove any existing service so we install a clean copy ---------------
$existing = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if ($existing) {
    Write-Host "Existing '$ServiceName' service found; stopping and removing it." -ForegroundColor Yellow

    if ($existing.Status -ne 'Stopped') {
        Stop-Service -Name $ServiceName -Force -ErrorAction SilentlyContinue
        try {
            $existing.WaitForStatus('Stopped', (New-TimeSpan -Seconds 30))
        }
        catch {
            Write-Host "  Warning: service did not report 'Stopped' in time; continuing." -ForegroundColor Yellow
        }
    }

    # sc.exe delete is the most compatible removal across PowerShell versions.
    & sc.exe delete $ServiceName | Out-Null

    if (-not (Wait-ServiceRemoved -Name $ServiceName -TimeoutSeconds 15)) {
        throw "Could not remove the existing '$ServiceName' service. Close anything using it (e.g. services.msc) and retry."
    }
}

# --- 3. Copy the published files into the install directory ------------------
Write-Host "Installing files to '$InstallDir'." -ForegroundColor Cyan
New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
Copy-Item -Path (Join-Path $PublishDir '*') -Destination $InstallDir -Recurse -Force

$exePath = Join-Path $InstallDir 'TreckAgent.exe'
if (-not (Test-Path $exePath)) {
    throw "Expected '$exePath' after copy but it is missing."
}

# --- 4. Ensure the writable log directory exists ----------------------------
# The service runs as LocalSystem, which can write under %ProgramData%. The
# agent redirects its file log here when hosted as a service (see Program.cs).
$dataDir = Join-Path $env:ProgramData 'TreckAgent'
$logDir  = Join-Path $dataDir 'logs'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
Write-Host "Log directory: $logDir" -ForegroundColor Cyan

# --- 5. Register the service ------------------------------------------------
# Quote the binary path so the SCM parses a Program Files path containing spaces.
$binaryPath = '"{0}"' -f $exePath
Write-Host "Registering service '$ServiceName' ($DisplayName), startup=Automatic." -ForegroundColor Cyan
New-Service -Name $ServiceName `
    -BinaryPathName $binaryPath `
    -DisplayName $DisplayName `
    -Description $Description `
    -StartupType Automatic | Out-Null

# --- 6. Environment-specific config selection -------------------------------
# The service process reads DOTNET_ENVIRONMENT to load appsettings.<Env>.json.
$serviceRegPath = "HKLM:\SYSTEM\CurrentControlSet\Services\$ServiceName"
Set-ItemProperty -Path $serviceRegPath -Name 'Environment' `
    -Value @("DOTNET_ENVIRONMENT=$Environment") -Type MultiString
Write-Host "Configured DOTNET_ENVIRONMENT=$Environment." -ForegroundColor Cyan

# --- 7. Automatic restart on failure ---------------------------------------
# Reset the failure counter daily; restart after 5s, then 10s, then 30s.
& sc.exe failure $ServiceName reset= 86400 actions= restart/5000/restart/10000/restart/30000 | Out-Null

# --- 8. Start and report ----------------------------------------------------
Write-Host "Starting '$ServiceName'." -ForegroundColor Cyan
Start-Service -Name $ServiceName
$svc = Get-Service -Name $ServiceName

Write-Host ""
Write-Host "Treck Agent installed." -ForegroundColor Green
Write-Host "  Service : $($svc.Name) ($($svc.DisplayName))"
Write-Host "  Status  : $($svc.Status)"
Write-Host "  Binary  : $exePath"
Write-Host "  Logs    : $logDir"
if (-not $SelfContained) {
    Write-Host "  Runtime : framework-dependent (requires the .NET 8 Desktop Runtime x64 on this machine)"
}
Write-Host ""
Write-Host "Manage with:  sc.exe query $ServiceName  |  Start-Service $ServiceName  |  Stop-Service $ServiceName"
