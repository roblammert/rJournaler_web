param(
    [Parameter(Mandatory = $true)]
    [string]$PackagePath,
    [string]$Tag = '1.0.1'
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
    throw 'docker is required on the host machine.'
}

if (-not (Test-Path -LiteralPath $PackagePath)) {
    throw "Package not found: $PackagePath"
}

$workRoot = Join-Path (Get-Location) '.build-tmp'
$workDir = Join-Path $workRoot ("rjournaler_web_{0}" -f $Tag)

if (Test-Path -LiteralPath $workDir) {
    Remove-Item -Recurse -Force -LiteralPath $workDir
}
New-Item -ItemType Directory -Path $workDir -Force | Out-Null

# Windows 10/11 includes bsdtar as tar.exe. This handles .tar.gz directly.
tar -xzf $PackagePath -C $workDir

Push-Location $workDir
try {
    $requiredFiles = @(
        'docker/php/Dockerfile',
        'docker/worker/Dockerfile',
        'python/requirements.txt',
        'python/worker/main.py',
        'public/index.php'
    )
    foreach ($required in $requiredFiles) {
        if (-not (Test-Path -LiteralPath $required -PathType Leaf)) {
            throw "Required file missing from package: $required. Recreate package using ./scripts/package-for-transfer.ps1 (default working-tree mode), then transfer again."
        }
    }

    Invoke-Docker buildx build --load -f docker/php/Dockerfile -t ("rjournaler-web-app:{0}" -f $Tag) .
    Invoke-Docker buildx build --load -f docker/worker/Dockerfile -t ("rjournaler-web-worker:{0}" -f $Tag) .

    # Validate that runtime-critical files are present in built images.
    Invoke-Docker run --rm --entrypoint sh ("rjournaler-web-app:{0}" -f $Tag) -c "test -f /var/www/html/public/index.php"
    Invoke-Docker run --rm --entrypoint sh ("rjournaler-web-worker:{0}" -f $Tag) -c "test -f /app/python/worker/main.py"
}
finally {
    Pop-Location
}

Write-Host "Built images:"
Write-Host ("  rjournaler-web-app:{0}" -f $Tag)
Write-Host ("  rjournaler-web-worker:{0}" -f $Tag)
