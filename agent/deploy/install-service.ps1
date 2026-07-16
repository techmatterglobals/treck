#Requires -Version 5.1
#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Installs (or reinstalls) the Treck Agent as a Windows Service.

.DESCRIPTION
    Copies a published build into the install directory, registers it with the
    Service Control Manager with automatic startup, configures the service to
    run under the Production configuration, sets up automatic restart on
    failure, ensures the log directory exists, and starts the service.

    Supply an already-published build via -PublishDir, or pass -Publish to build
    a self-contained release first (requires the .NET SDK).

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
    ASP.NET Core / .NET environment name (selects appsettings.<Env>.json).
    Default: Production.

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
    [string] $PublishDir = (Join-Path $PSScriptRoot '..\publish'),
    [string] $InstallDir = (Join-Path $env:ProgramFiles 'TreckAgent'),
    [string] $ServiceName = 'TreckAgent',
    [string] $DisplayName = 'Treck Agent',
    [string] $Description = 'Treck employee productivity and PC activity monitoring agent. Captures workstation session and activity telemetry and syncs it to the Treck server.',
    [ValidateSet('Production', 'Staging', 'Development')]
    [string] $Environment = 'Production'
)

$ErrorActionPreference = 'Stop'

# --- 1. Optionally publish a build ------------------------------------------
if ($Publish) {
    & (Join-Path $PSScriptRoot 'publish.ps1') -OutputDir $PublishDir -SelfContained:$SelfContained
}

$exeSource = Join-Path $PublishDir 'TreckAgent.exe'
if (-not (Test-Path $exeSource)) {
    throw "TreckAgent.exe not found in '$PublishDir'. Publish first (-Publish) or pass -PublishDir."
}

# --- 2. Remove any existing service so we install a clean copy ---------------
$existing = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if ($existing) {
    Write-Host "Existing '$ServiceName' service found — stopping and removing it." -ForegroundColor Yellow
    if ($existing.Status -ne 'Stopped') {
        Stop-Service -Name $ServiceName -Force -ErrorAction SilentlyContinue
        $existing.WaitForStatus('Stopped', (New-TimeSpan -Seconds 30))
    }
    # sc.exe delete is the most compatible removal across PowerShell versions.
    sc.exe delete $ServiceName | Out-Null
    Start-Sleep -Seconds 2
}

# --- 3. Copy the published files into the install directory ------------------
Write-Host "Installing files to '$InstallDir'." -ForegroundColor Cyan
New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
Copy-Item -Path (Join-Path $PublishDir '*') -Destination $InstallDir -Recurse -Force

$exePath = Join-Path $InstallDir 'TreckAgent.exe'

# --- 4. Ensure the writable log directory exists ----------------------------
# The service runs as LocalSystem, which can write under %ProgramData%. The
# agent redirects its file log here when hosted as a service (see Program.cs).
$dataDir = Join-Path $env:ProgramData 'TreckAgent'
$logDir = Join-Path $dataDir 'logs'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
Write-Host "Log directory: $logDir" -ForegroundColor Cyan

# --- 5. Register the service ------------------------------------------------
# Quote the binary path so the SCM parses a Program Files path with spaces.
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
# Reset the failure counter daily; restart after 5s, 10s, then 30s.
sc.exe failure $ServiceName reset= 86400 actions= restart/5000/restart/10000/restart/30000 | Out-Null

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
    Write-Host "  Runtime : framework-dependent — requires the .NET 8 Desktop Runtime (x64) on this machine"
}
Write-Host ""
Write-Host "Manage with:  sc.exe query $ServiceName  |  Start-Service $ServiceName  |  Stop-Service $ServiceName"
