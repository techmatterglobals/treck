#Requires -Version 5.1
<#
.SYNOPSIS
    Publishes a self-contained Windows build of the Treck Agent.

.DESCRIPTION
    Produces a framework-independent (self-contained) win-x64 build so the
    target workstation does not need the .NET runtime installed. Output goes to
    agent/publish by default. Run install-service.ps1 afterwards (or pass
    -Publish to install-service.ps1 to do both in one step).

.EXAMPLE
    ./publish.ps1
    ./publish.ps1 -RuntimeIdentifier win-x64 -OutputDir C:\Treck\publish
#>
[CmdletBinding()]
param(
    [string] $Configuration = 'Release',
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

Write-Host "Publishing Treck Agent ($Configuration, $RuntimeIdentifier, self-contained) → $OutputDir" -ForegroundColor Cyan

dotnet publish $projectPath `
    --configuration $Configuration `
    --runtime $RuntimeIdentifier `
    --self-contained true `
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
