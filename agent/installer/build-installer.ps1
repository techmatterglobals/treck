#Requires -Version 5.1
<#
.SYNOPSIS
    Builds the Treck Agent installer: self-contained publish → managed custom
    action → MSI → Burn bootstrapper (TreckAgentSetup.exe).

.DESCRIPTION
    Run on a Windows build host with the .NET 8 SDK and the WiX v5 toolset:
        dotnet tool install --global wix --version 5.0.2
        wix extension add -g WixToolset.Util.wixext/5.0.2
        wix extension add -g WixToolset.Bal.wixext/5.0.2

    Steps:
      1. Publish the agent self-contained (win-x64) via deploy\publish.ps1.
      2. Build the managed custom-action DLL (net472, WixToolset.Dtf).
      3. Build the MSI (Treck.Agent.Installer.msi).
      4. Build the bundle (TreckAgentSetup.exe) chaining the MSI.

    Nothing here modifies the legacy C:\TreckAgent-Install installation.

.PARAMETER Configuration
    Build configuration (default Release).

.PARAMETER OutputDir
    Where the final artifacts are placed (default: .\artifacts).
#>
[CmdletBinding()]
param(
    [string] $Configuration = 'Release',
    [string] $OutputDir
)

$ErrorActionPreference = 'Stop'
$scriptDir = $PSScriptRoot
if (-not $scriptDir) { $scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path }
if (-not $OutputDir) { $OutputDir = Join-Path $scriptDir 'artifacts' }

$agentDir   = Split-Path -Parent $scriptDir            # ..\  (the agent project)
$publishDir = Join-Path $scriptDir 'publish'           # self-contained output
$caProj     = Join-Path $scriptDir 'ca\Treck.Agent.Installer.CA.csproj'
$msiProj    = Join-Path $scriptDir 'Treck.Agent.Installer.wixproj'
$bundleProj = Join-Path $scriptDir 'Treck.Agent.Bootstrapper.wixproj'

New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null

# --- 1. Publish the agent self-contained (win-x64) ---------------------------
Write-Host '== 1/4  Publishing agent (self-contained win-x64) ==' -ForegroundColor Cyan
& (Join-Path $agentDir 'deploy\publish.ps1') -Configuration $Configuration -SelfContained -RuntimeIdentifier win-x64 -OutputDir $publishDir
if (-not (Test-Path (Join-Path $publishDir 'TreckAgent.exe'))) {
    throw "Publish did not produce TreckAgent.exe in $publishDir"
}

# --- 2. Build the managed custom action -------------------------------------
Write-Host '== 2/4  Building enrollment custom action ==' -ForegroundColor Cyan
dotnet build $caProj -c $Configuration
if ($LASTEXITCODE -ne 0) { throw 'Custom-action build failed.' }
# Locate the packaged CA DLL (WixToolset.Dtf output; commonly *.CA.dll).
$caDll = Get-ChildItem -Path (Join-Path $scriptDir 'ca\bin') -Recurse -Filter '*.dll' |
    Where-Object { $_.Name -match 'Treck\.Agent\.Installer\.CA(\.CA)?\.dll$' } |
    Select-Object -First 1
if (-not $caDll) { throw 'Could not find the packaged custom-action DLL under ca\bin.' }
$caDir = $caDll.DirectoryName
Write-Host "   Custom action: $($caDll.FullName)" -ForegroundColor DarkGray

# --- 3. Build the MSI --------------------------------------------------------
Write-Host '== 3/4  Building MSI ==' -ForegroundColor Cyan
dotnet build $msiProj -c $Configuration "-p:PublishDir=$publishDir" "-p:CaDir=$caDir"
if ($LASTEXITCODE -ne 0) { throw 'MSI build failed.' }
$msi = Get-ChildItem -Path $scriptDir -Recurse -Filter 'Treck.Agent.Installer.msi' | Select-Object -First 1
if (-not $msi) { throw 'MSI was not produced.' }
Copy-Item $msi.FullName (Join-Path $OutputDir 'Treck.Agent.Installer.msi') -Force

# --- 4. Build the bootstrapper (TreckAgentSetup.exe) -------------------------
Write-Host '== 4/4  Building bootstrapper (TreckAgentSetup.exe) ==' -ForegroundColor Cyan
dotnet build $bundleProj -c $Configuration "-p:MsiPath=$($msi.FullName)"
if ($LASTEXITCODE -ne 0) { throw 'Bundle build failed.' }
$setup = Get-ChildItem -Path $scriptDir -Recurse -Filter 'TreckAgentSetup.exe' | Select-Object -First 1
if (-not $setup) { throw 'TreckAgentSetup.exe was not produced.' }
Copy-Item $setup.FullName (Join-Path $OutputDir 'TreckAgentSetup.exe') -Force

Write-Host ''
Write-Host "Done. Artifacts in $OutputDir :" -ForegroundColor Green
Write-Host '  TreckAgentSetup.exe   (final user-facing installer)'
Write-Host '  Treck.Agent.Installer.msi'
