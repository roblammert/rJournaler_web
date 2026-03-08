param(
    [Parameter(Mandatory = $true)]
    [string]$PackagePath,
    [string]$Tag = '1.0.1',
    [switch]$SkipChecksum,
    [switch]$SkipMigrate,
    [string]$ComposeFile = 'docker-compose.images.yml'
)

$ErrorActionPreference = 'Stop'

function Invoke-Docker {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]]$Args)
    & docker @Args
    if ($LASTEXITCODE -ne 0) {
        throw ("docker command failed (exit {0}): docker {1}" -f $LASTEXITCODE, ($Args -join ' '))
    }
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'docker is required on this machine.'
}

function Assert-LocalImage {
    param([Parameter(Mandatory = $true)][string]$Image)
    $id = (& docker image inspect --format '{{.Id}}' $Image 2>$null)
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0) {
        throw "Required local image not found: $Image"
    }
    if (-not $id) {
        throw "Required local image not found: $Image"
    }
}

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoRoot = Resolve-Path (Join-Path $scriptDir '..')
$resolvedPackage = Resolve-Path $PackagePath
$buildScript = Join-Path $scriptDir 'build-images-from-package.ps1'

if (-not (Test-Path -LiteralPath $resolvedPackage -PathType Leaf)) {
    throw "Package not found: $PackagePath"
}

if (-not (Test-Path -LiteralPath $buildScript -PathType Leaf)) {
    throw "Build script not found: $buildScript"
}

if (-not $SkipChecksum) {
    $checksumPath = "$resolvedPackage.sha256"
    if (-not (Test-Path -LiteralPath $checksumPath -PathType Leaf)) {
        throw "Checksum file not found: $checksumPath"
    }

    $checksumLine = (Get-Content -Path $checksumPath -TotalCount 1).Trim()
    if ($checksumLine -eq '') {
        throw "Checksum file is empty: $checksumPath"
    }

    $expected = ($checksumLine.Split(' ')[0]).Trim().ToLowerInvariant()
    $actual = (Get-FileHash -Algorithm SHA256 -Path $resolvedPackage).Hash.ToLowerInvariant()
    if ($actual -ne $expected) {
        throw "Checksum mismatch for package. Expected $expected, got $actual"
    }

    Write-Host "Checksum OK: $resolvedPackage"
}

Push-Location $repoRoot
try {
    & $buildScript -PackagePath $resolvedPackage -Tag $Tag

    Assert-LocalImage -Image ("rjournaler-web-app:{0}" -f $Tag)
    Assert-LocalImage -Image ("rjournaler-web-worker:{0}" -f $Tag)

    $env:IMAGE_TAG = $Tag
    Invoke-Docker compose -f $ComposeFile up -d --force-recreate --remove-orphans --pull never

    if (-not $SkipMigrate) {
        Invoke-Docker compose -f $ComposeFile run --rm app php scripts/migrate.php
    }

    Write-Host "Deploy complete."
    Write-Host "  IMAGE_TAG: $Tag"
    Write-Host "  Compose file: $ComposeFile"
    if ($SkipMigrate) {
        Write-Host "  Migration: skipped"
    } else {
        Write-Host "  Migration: executed"
    }
}
finally {
    Pop-Location
}
