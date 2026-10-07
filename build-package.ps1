# Build script for cs-page-protector
# Produces a single installable package at the repo root:
#   pkg_cspageprotector_v{version}_{YYYYMMDD}_{HHMM}.zip
# which wraps the admin component and the system plugin. Install
# this one zip; Joomla unpacks it and installs both child extensions.
#
# Zips are built with 7-Zip because PowerShell's Compress-Archive
# omits directory entries, which breaks Joomla's installer.

param(
    [string]$Version = ""
)

$ErrorActionPreference = "Stop"

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$sevenZip  = "C:\Program Files\7-Zip\7z.exe"

if (-not (Test-Path $sevenZip)) {
    throw "7-Zip not found at $sevenZip. Install 7-Zip or update the path in build-package.ps1."
}

# UTF-8 BOM check across every PHP / XML / INI in the source tree.
# A BOM in front of a `<?php` opener emits raw bytes before the PHP
# parser starts, which breaks declare(strict_types=1). Fail here
# rather than at install time.
$bomFiles = @()
$packagesPath = Join-Path $scriptDir "packages"
Get-ChildItem -Path $packagesPath -Recurse -Include "*.php", "*.xml", "*.ini" -File | ForEach-Object {
    $head = [byte[]](Get-Content -LiteralPath $_.FullName -Encoding Byte -ReadCount 0 -TotalCount 3)
    if ($head.Count -ge 3 -and $head[0] -eq 0xEF -and $head[1] -eq 0xBB -and $head[2] -eq 0xBF) {
        $bomFiles += $_.FullName
    }
}
if ($bomFiles.Count -gt 0) {
    Write-Host "ABORT: UTF-8 BOM detected in the following file(s):" -ForegroundColor Red
    $bomFiles | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }
    throw "Strip the BOM and re-run."
}

$pkgDir      = Join-Path $scriptDir "packages\pkg_cspageprotector"
$pkgManifest = Join-Path $pkgDir "pkg_cspageprotector.xml"

if (-not (Test-Path $pkgManifest)) {
    throw "Package manifest not found at $pkgManifest"
}

if ([string]::IsNullOrEmpty($Version)) {
    $manifestContents = Get-Content $pkgManifest -Raw
    if ($manifestContents -match '<version>([^<]+)</version>') {
        $Version = $matches[1]
    } else {
        throw "Could not read <version> from $pkgManifest"
    }
}

# Every manifest must carry the same version, or Joomla's update tracking
# drifts between the package and its children.
$manifests = @(
    (Join-Path $scriptDir "packages\com_cspageprotector\cspageprotector.xml"),
    (Join-Path $scriptDir "packages\plg_system_cspageprotector\cspageprotector.xml")
)
foreach ($m in $manifests) {
    if ((Get-Content $m -Raw) -match '<version>([^<]+)</version>') {
        if ($matches[1] -ne $Version) {
            throw "Version mismatch: $m says $($matches[1]), package says $Version"
        }
    }
}

# Empty folders in a package blow up Joomla's installer later (Brain gotcha #22).
$emptyDirs = Get-ChildItem -Path $packagesPath -Recurse -Directory | Where-Object {
    @(Get-ChildItem -LiteralPath $_.FullName -Recurse -File).Count -eq 0
}
if ($emptyDirs) {
    $emptyDirs | ForEach-Object { Write-Host "Empty dir: $($_.FullName)" -ForegroundColor Red }
    throw "Remove the empty folder(s) or add a file to them."
}

$childExtensions = @(
    @{
        Name      = "com_cspageprotector"
        SourceDir = Join-Path $scriptDir "packages\com_cspageprotector"
        Contents  = @("cspageprotector.xml", "admin", "site", "media")
    },
    @{
        Name      = "plg_system_cspageprotector"
        SourceDir = Join-Path $scriptDir "packages\plg_system_cspageprotector"
        Contents  = @("cspageprotector.xml", "services", "src", "language")
    }
)

$timestamp  = Get-Date -Format "yyyyMMdd_HHmm"
$pkgZipName = "pkg_cspageprotector_v${Version}_${timestamp}.zip"
$pkgZipPath = Join-Path $scriptDir $pkgZipName

# Clean old builds for this version
Get-ChildItem -Path $scriptDir -Filter "pkg_cspageprotector_v${Version}*.zip" -ErrorAction SilentlyContinue | Remove-Item -Force
Get-ChildItem -Path $scriptDir -Filter "com_cspageprotector.zip" -ErrorAction SilentlyContinue | Remove-Item -Force
Get-ChildItem -Path $scriptDir -Filter "plg_system_cspageprotector.zip" -ErrorAction SilentlyContinue | Remove-Item -Force

$pkgStage = Join-Path $scriptDir "build"
if (Test-Path $pkgStage) { Remove-Item $pkgStage -Recurse -Force }
New-Item -ItemType Directory -Path $pkgStage | Out-Null
New-Item -ItemType Directory -Path (Join-Path $pkgStage "packages") | Out-Null
New-Item -ItemType Directory -Path (Join-Path $pkgStage "language\en-GB") | Out-Null

Write-Host "Building pkg_cspageprotector v$Version ..." -ForegroundColor Cyan

# 1. Build each child extension into the staging packages/ folder with
#    a stable, non-timestamped filename.
foreach ($ext in $childExtensions) {
    Write-Host "  Child: $($ext.Name)" -ForegroundColor DarkCyan

    $childStage = Join-Path $scriptDir "build-child"
    if (Test-Path $childStage) { Remove-Item $childStage -Recurse -Force }
    New-Item -ItemType Directory -Path $childStage | Out-Null

    foreach ($item in $ext.Contents) {
        $source = Join-Path $ext.SourceDir $item
        if (Test-Path $source) {
            $dest = Join-Path $childStage $item
            if ((Get-Item $source).PSIsContainer) {
                Copy-Item $source $dest -Recurse
            } else {
                Copy-Item $source $dest
            }
        }
    }

    $childZipPath = Join-Path $pkgStage "packages\$($ext.Name).zip"

    Push-Location $childStage
    try {
        & $sevenZip a -tzip $childZipPath * | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "7-Zip failed on $($ext.Name) (exit $LASTEXITCODE)" }
    } finally {
        Pop-Location
    }

    Remove-Item $childStage -Recurse -Force
}

# 2. Copy package manifest + script + language into staging root.
Copy-Item $pkgManifest (Join-Path $pkgStage "pkg_cspageprotector.xml")
Copy-Item (Join-Path $pkgDir "script.php") (Join-Path $pkgStage "script.php")
Copy-Item (Join-Path $pkgDir "language\en-GB\pkg_cspageprotector.sys.ini") (Join-Path $pkgStage "language\en-GB\pkg_cspageprotector.sys.ini")

# 3. Zip the whole package.
Push-Location $pkgStage
try {
    & $sevenZip a -tzip $pkgZipPath * | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "7-Zip failed on package (exit $LASTEXITCODE)" }
} finally {
    Pop-Location
}

Remove-Item $pkgStage -Recurse -Force

$sizeKb = [Math]::Round((Get-Item $pkgZipPath).Length / 1KB, 1)
Write-Host "Created $pkgZipName ($sizeKb KB)" -ForegroundColor Green

# 4. Report sha256 so the user can paste it into updates.xml
$sha256 = (Get-FileHash $pkgZipPath -Algorithm SHA256).Hash.ToLower()
Write-Host "SHA256: $sha256" -ForegroundColor Yellow
Write-Host ""
Write-Host "Update updates.xml with:" -ForegroundColor Gray
Write-Host "  <version>$Version</version>" -ForegroundColor Gray
Write-Host "  <sha256>$sha256</sha256>" -ForegroundColor Gray
Write-Host "  <downloadurl>https://github.com/cybersalt/cs-page-protector/releases/download/v$Version/$pkgZipName</downloadurl>" -ForegroundColor Gray
