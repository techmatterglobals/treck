#Requires -Version 5.1
<#
.SYNOPSIS
    Publishes a Windows build of the Treck Agent.

.DESCRIPTION
    Framework-dependent by default and RID-less: produces TreckAgent.exe using the
    SDK's apphost and the machine's installed shared runtime. Nothing is
    downloaded, so it works on locked-down/slow networks. The target machine needs
    the .NET 8 Desktop Runtime (x64) installed (required because the agent's
    session detection uses Microsoft.Win32.SystemEvents, part of the Windows
    Desktop shared framework).

    Pass -SelfContained for a release build that bundles the runtime (no runtime
    needed on the target). Only this path specifies a RID and therefore downloads
    the ~130 MB win-x64 runtime packs — run it on a build host with connectivity.

    Output goes to agent/publish by default. Run install-service.ps1 afterwards
    (or pass -Publish to install-service.ps1 to do both in one step).

.EXAMPLE
    ./publish.ps1                    # framework-dependent, RID-less (dev/default; no downloads)
    ./publish.ps1 -SelfContained    # release build with the runtime bundled
    ./publish.ps1 -SelfContained -RuntimeIdentifier win-x64 -OutputDir C:\Treck\publish
#>
[CmdletBinding()]
param(
    [string] $Configuration = 'Release',
    [switch] $SelfContained,
    [string] $RuntimeIdentifier = 'win-x64',
    [string] $OutputDir = (Join-Path $PSScriptRoot '..\publish')
)

$ErrorActionPreference = 'Stop'

$projectPath = Join-Path $PSScriptRoot '..\Treck.Agent.csproj'
if (-not (Test-Path $projectPath)) {
    throw "Cannot find Treck.Agent.csproj at $projectPath"
}

if (-not (Get-Command dotnet -ErrorAction SilentlyContinue)) {
    throw 'The .NET SDK (dotnet) is required to publish the agent but was not found on PATH.'
}

if ($SelfContained) {
    Write-Host "Publishing Treck Agent ($Configuration, $RuntimeIdentifier, self-contained) → $OutputDir" -ForegroundColor Cyan
    # Only this path pins a RID and pulls the runtime packs.
    dotnet publish $projectPath `
        --configuration $Configuration `
        --runtime $RuntimeIdentifier `
        --self-contained true `
        /p:PublishSingleFile=false `
        --output $OutputDir
}
else {
    Write-Host "Publishing Treck Agent ($Configuration, framework-dependent, RID-less) → $OutputDir" -ForegroundColor Cyan
    # No --runtime: uses the SDK apphost + the installed shared runtime; no downloads.
    dotnet publish $projectPath `
        --configuration $Configuration `
        --output $OutputDir
}

if ($LASTEXITCODE -ne 0) {
    throw "dotnet publish failed with exit code $LASTEXITCODE"
}

$exePath = Join-Path $OutputDir 'TreckAgent.exe'
if (-not (Test-Path $exePath)) {
    throw "Publish completed but $exePath was not produced."
}

Write-Host "Published: $exePath" -ForegroundColor Green
if (-not $SelfContained) {
    Write-Host "Framework-dependent build — ensure the .NET 8 Desktop Runtime (x64) is installed on the target." -ForegroundColor Yellow
}
