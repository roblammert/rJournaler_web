param(
    [string]$OutputDir = "dist",
    [string]$Ref = "HEAD",
    [ValidateSet('working-tree', 'git-ref')]
    [string]$Mode = 'working-tree'
)

$ErrorActionPreference = 'Stop'

if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    throw 'git is required to package sources.'
}

$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$outputPath = Join-Path $OutputDir "rjournaler_web-src-$timestamp.tar.gz"
$checksumPath = "$outputPath.sha256"

New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null

if ($Mode -eq 'git-ref') {
    git archive --format=tar.gz --output $outputPath $Ref
} else {
    $stageRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("rjournaler_pkg_{0}" -f ([Guid]::NewGuid().ToString('N')))
    New-Item -ItemType Directory -Path $stageRoot -Force | Out-Null
    try {
        $repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
        Push-Location $repoRoot
        try {
            $files = git ls-files --cached --others --exclude-standard
        } finally {
            Pop-Location
        }

        foreach ($relativePath in $files) {
            if ([string]::IsNullOrWhiteSpace($relativePath)) {
                continue
            }
            $sourcePath = Join-Path $repoRoot $relativePath
            if (-not (Test-Path -LiteralPath $sourcePath -PathType Leaf)) {
                continue
            }

            $destinationPath = Join-Path $stageRoot $relativePath
            $destinationDir = Split-Path -Parent $destinationPath
            if ($destinationDir -and -not (Test-Path -LiteralPath $destinationDir)) {
                New-Item -ItemType Directory -Path $destinationDir -Force | Out-Null
            }
            Copy-Item -LiteralPath $sourcePath -Destination $destinationPath -Force
        }

        tar -czf $outputPath -C $stageRoot .
    } finally {
        if (Test-Path -LiteralPath $stageRoot) {
            Remove-Item -Recurse -Force -LiteralPath $stageRoot
        }
    }
}

if (-not (Test-Path $outputPath)) {
    throw "Package was not created: $outputPath"
}

$hash = Get-FileHash -Algorithm SHA256 -Path $outputPath
Set-Content -Path $checksumPath -Value ("{0}  {1}" -f $hash.Hash.ToLowerInvariant(), (Split-Path $outputPath -Leaf)) -Encoding ASCII

Write-Host "Package created: $outputPath"
Write-Host "Checksum file:   $checksumPath"
Write-Host "Package mode:    $Mode"
Write-Host "Transfer both files to your Docker host and verify with sha256sum -c."
