#Requires -Version 5.1
<#
.SYNOPSIS
    Publishes a Windows build of the Treck Agent.

.DESCRIPTION
    Framework-dependent by default: produces a small win-x64 build (TreckAgent.exe)
    that requires the .NET 8 Desktop Runtime on the target machine. This restore
    only downloads the tiny apphost pack, so it works on locked-down/slow networks.

    Pass -SelfContained to bundle the runtime instead (no runtime needed on the
    target). That build must download the ~130 MB win-x64 runtime packs
    (Microsoft.NETCore.App.Runtime + Microsoft.WindowsDesktop.App.Runtime), so run
    it on a build host with good connectivity.

    Output goes to agent/publish by default. Run install-service.ps1 afterwards
    (or pass -Publish to install-service.ps1 to do both in one step).

.EXAMPLE
    ./publish.ps1                     # framework-dependent (needs Desktop Runtime on target)
    ./publish.ps1 -SelfContained     # bundle the runtime (larger; downloads runtime packs)
    ./publish.ps1 -RuntimeIdentifier win-x64 -OutputDir C:\Treck\publish
#>
[CmdletBinding()]
param(
    [string] $Configuration = 'Release',
    [string] $RuntimeIdentifier = 'win-x64',
    [switch] $SelfContained,
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

$selfContainedValue = if ($SelfContained) { 'true' } else { 'false' }
$mode = if ($SelfContained) { 'self-contained' } else { 'framework-dependent (requires .NET 8 Desktop Runtime on target)' }

Write-Host "Publishing Treck Agent ($Configuration, $RuntimeIdentifier, $mode) → $OutputDir" -ForegroundColor Cyan

dotnet publish $projectPath `
    --configuration $Configuration `
    --runtime $RuntimeIdentifier `
    --self-contained $selfContainedValue `
    /p:PublishSingleFile=false `
    --output $OutputDir

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
