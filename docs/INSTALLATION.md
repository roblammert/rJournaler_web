
# rJournaler_Web Installation Guide

This guide covers the Windows-to-Windows deployment flow for rJournaler_Web v1.0.5:

- source machine: package and transfer build artifact
- Docker host machine: build images locally and run containers
- external MySQL: no DB container in compose

## 1. Prerequisites

On the Docker host machine:

- Docker Desktop (Linux containers mode)
- PowerShell 5.1+ or PowerShell 7+
- Network access to your external MySQL server
- Network access to Ollama host (if `OLLAMA_REQUIRED=true`)

On the source machine:

- Git
- PowerShell

## 2. Prepare Environment File

You must create/copy `.env` in the project root on the Docker host.

From project root:

```powershell
Copy-Item .env.example .env
```

Then edit `.env` with your real values.

Minimum required keys:

- `APP_ENV`
- `APP_DEBUG`
- `APP_URL`
- `APP_TIMEZONE`
- `APP_KEY`
- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`
- `DB_CHARSET`

Processing/weather keys to verify:

- `OLLAMA_URL`
- `OLLAMA_MODEL`
- `OLLAMA_REQUIRED`
- `OLLAMA_TIMEOUT_SECONDS`
- `PIPELINE_CONFIG_PATH`
- `PIPELINE_PROMPT_DIR`

Notes:

- `APP_URL` should be the URL users actually open (not always `localhost`).
- `DB_HOST` must be reachable from containers running on the Docker host.


## 3. Package On Source Machine

From source repo root:

```powershell
.\scripts\package-for-transfer.ps1
```

This generates files in `dist/`:

- `rjournaler_web-src-<timestamp>.tar.gz`
- `rjournaler_web-src-<timestamp>.tar.gz.sha256`

Transfer both files to the Docker host.

## 4. Verify Package On Docker Host

In the folder containing the transferred files:

```powershell
$pkg = "rjournaler_web-src-YYYYMMDD-HHMMSS.tar.gz"
$expected = (Get-Content "$pkg.sha256").Split(' ')[0].Trim().ToLowerInvariant()
$actual = (Get-FileHash -Algorithm SHA256 $pkg).Hash.ToLowerInvariant()
if ($actual -ne $expected) { throw "Checksum mismatch" }
```

## 5. Build + Deploy On Docker Host

**Build images for v1.0.5:**

```powershell
./scripts/build-images-from-package.ps1 -PackagePath .\rjournaler_web-src-YYYYMMDD-HHMMSS.tar.gz -Tag 1.0.5
```

What this does:

- builds app and worker images locally
- verifies local images exist
- starts containers with `docker-compose.images.yml`
- runs migrations (unless `-SkipMigrate` is used)

## 6. Confirm Running Services

```powershell
$env:IMAGE_TAG = "1.0.5"
docker compose -f .\rjournaler_web-src-YYYYMMDD-HHMMSS\docker-compose.images.yml ps
```

Open app:

- `http://<docker-host>:8080`


## 7. Useful Operations

Tail logs:

```powershell
$env:IMAGE_TAG = "1.0.5"
docker compose -f .\rjournaler_web-src-YYYYMMDD-HHMMSS\docker-compose.images.yml logs -f app worker
```

Re-run migrations:

```powershell
$env:IMAGE_TAG = "1.0.5"
docker compose -f .\rjournaler_web-src-YYYYMMDD-HHMMSS\docker-compose.images.yml run --rm app php scripts/migrate.php
```

Stop/remove containers:

```powershell
$env:IMAGE_TAG = "1.0.5"
docker compose -f .\rjournaler_web-src-YYYYMMDD-HHMMSS\docker-compose.images.yml down
```

## 8. Exporting Journal Entries (New in v1.0.5)

You can now export your journal entries in Markdown format from the Export Entries page. Choose a time range (day, week, month, months, year, or years). For multi-file exports, a ZIP file will be provided. The export matches your user and only includes your entries.

## 8. Quick Troubleshooting

- `pull access denied for rjournaler-web-app`:
  - ensure deploy/build scripts are the newest packaged version
  - ensure local image exists: `docker image inspect rjournaler-web-app:<tag>`
- Weather stuck refreshing:
  - confirm app container is updated to latest image/tag
  - check app logs for Python/weather errors
- DB connection failure:
  - verify `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD` in `.env`
  - verify Docker host can reach external MySQL
