
# rJournaler_web

Production-ready journaling web application using PHP + JavaScript + MySQL, with Python Optimus/Autobot background workers.
Current release: `1.0.6`

## Highlights in 1.0.6

- Removed TOTP and trusted device authentication features for simplified login and maintenance.
- App version code is now auto-generated from the version string (e.g., W010006 for 1.0.6).
- Bug fixes and codebase cleanup for authentication and entry UID logic.

See `docs/releases/v1.0.6-release-notes.md` for full highlights and upgrade steps.

## Docker (External MySQL)

This repository includes a minimal Docker setup for:

- `app` (PHP web server)
- `worker` (Python Optimus/Autobot)

No MySQL container is included. Use your existing external MySQL instance.

### Build and Deploy for v1.0.6

**Build images from a transfer package on the Docker host:**

1. Set external DB connection values in `.env`.
If MySQL runs on the same machine as Docker engine: set `DB_HOST=host.docker.internal` (requires host-gateway support).
If MySQL is remote: set `DB_HOST` to that hostname/IP.
If Docker runs on a different machine, do not use `127.0.0.1` for DB unless MySQL is inside that same remote machine.


2. Start containers.

```powershell
./scripts/docker.ps1 up
```

If Docker engine is remote, use either context or host:

```powershell
./scripts/docker.ps1 up -Context my-remote-context
# or
./scripts/docker.ps1 up -DockerHost tcp://remote-docker-host:2375
```



3. Run migrations.

```powershell
./scripts/docker.ps1 migrate
```


4. Open app:
Local engine: `http://localhost:8080`
Remote engine: `http://<remote-docker-host>:8080`


5. Set `APP_URL` in `.env` to the externally reachable address (especially when engine is remote).

Useful commands:


  ```bash
  sha256sum -c rjournaler_web-src-YYYYMMDD-HHMMSS.tar.gz.sha256
  ```

3. Build images directly on Docker host.

  Windows Docker host (PowerShell):
  ```

  The build scripts use `docker buildx build --load` so images are guaranteed to be available in the local Docker image store for compose runs.

  Linux Docker host:

  ```bash

4. Run with image-based compose (no source bind mounts).

  Windows Docker host (PowerShell):

  ```powershell
  $env:IMAGE_TAG = "1.0.2"
  docker compose -f docker-compose.images.yml up -d
  ```

  The image-based compose file is configured with `pull_policy: never`, so it will only use locally built images and will not attempt registry pulls.

  Linux Docker host:

  ```bash
  IMAGE_TAG=1.0.2 docker compose -f docker-compose.images.yml up -d
  ```

5. Run migrations against your external DB.

  Windows Docker host (PowerShell):

  ```powershell
  $env:IMAGE_TAG = "1.0.2"
  docker compose -f docker-compose.images.yml run --rm app php scripts/migrate.php
  ```

  Linux Docker host:

  ```bash
  IMAGE_TAG=1.0.2 docker compose -f docker-compose.images.yml run --rm app php scripts/migrate.php
  ```

## Cross-Platform Notes

- Worker control APIs support Windows and Linux kill/restart behavior.
- `scripts/weather-refresh.php` resolves Python executables for both `.venv/Scripts/python.exe` and `.venv/bin/python` with fallback.
- Status dashboard helper commands are OS-aware and path-quoted for directories with spaces.

## Installation Guide

- `docs/INSTALLATION.md`

## Worker Pipeline

Configured by `python/worker/pipeline_stages.json`:

1. `meta_group_0`
2. `meta_group_1`
3. `meta_group_2_llm`
4. `meta_group_3_weather`
5. `metrics_finalize`

Processing behavior:

- Retries with backoff for recoverable failures.
- Specialized retry flow for Ollama unavailability.
- Guardrails prevent premature `COMPLETE` when required metadata is missing.
- Stale lock and orphaned worker self-healing.
- Retention cleanup for queue/audit/orchestrator logs.

## Admin and Operations

- Live logs in `public/logviewer.php` backed by `orchestrator_logs`.
- Queue management and worker controls in `public/dashboards/status.php`.
- Targeted reprocess with filters, dry-run preview, presets, max rows, and batch sizing in `public/admin-reprocess.php`.
- Correlation-engine cleanup utility moved to legacy location:
  - `scripts/legacy/cleanup-correlation-jobs.php`

## Import and Security Tooling

- User/admin bootstrap scripts in `scripts/`:
  - `bootstrap-admin.php`
  - `create-user.php`
  - `generate-totp-secret.php`
  - `generate-otpauth-uri.php`
  - `encrypt-totp-secret.php`
  - `build-app-version-code.php`

- Import and repair scripts:
  - `queue-meta-group-3-backfill.php`
  - `repair-complete-metadata.php`
  - `patch_entry_uid_created_at.py`

## Validation

```powershell
php tests/php/entry_uid_smoke_test.php
php tests/php/monthly_import_parser_test.php
python -m unittest discover -s tests/python -p "test_*.py" -v
```
**Build images from a transfer package on the Docker host:**

```powershell
./scripts/build-images-from-package.ps1 -PackagePath .\rjournaler_web-src-YYYYMMDD-HHMMSS.tar.gz -Tag 1.0.6
```

**Run with image-based compose:**

```powershell
$env:IMAGE_TAG = "1.0.6"
docker compose -f docker-compose.images.yml up -d
```

See the installation guide for full details.

1. Set external DB connection values in `.env`.

- If MySQL runs on the same machine as Docker engine: set `DB_HOST=host.docker.internal` (requires host-gateway support).
- If MySQL is remote: set `DB_HOST` to that hostname/IP.
- If Docker runs on a different machine, do not use `127.0.0.1` for DB unless MySQL is inside that same remote machine.

2. Start containers.

```powershell
./scripts/docker.ps1 up
```

If Docker engine is remote, use either context or host:

```powershell
./scripts/docker.ps1 up -Context my-remote-context
# or
./scripts/docker.ps1 up -DockerHost tcp://remote-docker-host:2375
```

3. Run migrations.

```powershell
./scripts/docker.ps1 migrate

- Local engine: `http://localhost:8080`
- Remote engine: `http://<remote-docker-host>:8080`
5. Set `APP_URL` in `.env` to the externally reachable address (especially when engine is remote).


Useful commands:

```powershell
./scripts/docker.ps1 logs
./scripts/docker.ps1 logs -Service worker
./scripts/docker.ps1 down
```


### Build On Docker Host From Transfer Package
```powershell
./scripts/package-for-transfer.ps1
```
```powershell
./scripts/package-for-transfer.ps1 -Mode git-ref -Ref HEAD
```


Windows Docker host (PowerShell):

```powershell
$pkg = "rjournaler_web-src-YYYYMMDD-HHMMSS.tar.gz"
$expected = (Get-Content "$pkg.sha256").Split(' ')[0].Trim().ToLowerInvariant()
$actual = (Get-FileHash -Algorithm SHA256 $pkg).Hash.ToLowerInvariant()
```

Linux Docker host:

```bash
sha256sum -c rjournaler_web-src-YYYYMMDD-HHMMSS.tar.gz.sha256
```

3. Build images directly on Docker host.

Windows Docker host (PowerShell):

```powershell
./scripts/build-images-from-package.ps1 -PackagePath .\rjournaler_web-src-YYYYMMDD-HHMMSS.tar.gz -Tag 1.0.2
```

The build scripts use `docker buildx build --load` so images are guaranteed to be available in the local Docker image store for compose runs.

Linux Docker host:

```bash
./scripts/build-images-from-package.sh rjournaler_web-src-YYYYMMDD-HHMMSS.tar.gz 1.0.2
```

4. Run with image-based compose (no source bind mounts).

Windows Docker host (PowerShell):

```powershell
$env:IMAGE_TAG = "1.0.2"
docker compose -f docker-compose.images.yml up -d
```

The image-based compose file is configured with `pull_policy: never`, so it will only use locally built images and will not attempt registry pulls.

Linux Docker host:

```bash
IMAGE_TAG=1.0.2 docker compose -f docker-compose.images.yml up -d
```

5. Run migrations against your external DB.

Windows Docker host (PowerShell):

```powershell
$env:IMAGE_TAG = "1.0.2"
docker compose -f docker-compose.images.yml run --rm app php scripts/migrate.php
```

Linux Docker host:

```bash
IMAGE_TAG=1.0.2 docker compose -f docker-compose.images.yml run --rm app php scripts/migrate.php
```

## Cross-Platform Notes

- Worker control APIs support Windows and Linux kill/restart behavior.
- `scripts/weather-refresh.php` resolves Python executables for both `.venv/Scripts/python.exe` and `.venv/bin/python` with fallback.
- Status dashboard helper commands are OS-aware and path-quoted for directories with spaces.

## Installation Guide

- `docs/INSTALLATION.md`

## Worker Pipeline

Configured by `python/worker/pipeline_stages.json`:

1. `meta_group_0`
2. `meta_group_1`
3. `meta_group_2_llm`
4. `meta_group_3_weather`
5. `metrics_finalize`

Processing behavior:

- Retries with backoff for recoverable failures.
- Specialized retry flow for Ollama unavailability.
- Guardrails prevent premature `COMPLETE` when required metadata is missing.
- Stale lock and orphaned worker self-healing.
- Retention cleanup for queue/audit/orchestrator logs.

## Admin and Operations

- Live logs in `public/logviewer.php` backed by `orchestrator_logs`.
- Queue management and worker controls in `public/dashboards/status.php`.
- Targeted reprocess with filters, dry-run preview, presets, max rows, and batch sizing in `public/admin-reprocess.php`.
- Correlation-engine cleanup utility moved to legacy location:
  - `scripts/legacy/cleanup-correlation-jobs.php`

## Import and Security Tooling

- User/admin bootstrap scripts in `scripts/`:
  - `bootstrap-admin.php`
  - `create-user.php`
  - `generate-totp-secret.php`
  - `generate-otpauth-uri.php`
  - `encrypt-totp-secret.php`
  - `build-app-version-code.php`

- Import and repair scripts:
  - `queue-meta-group-3-backfill.php`
  - `repair-complete-metadata.php`
  - `patch_entry_uid_created_at.py`

## Validation

```powershell
php tests/php/entry_uid_smoke_test.php
php tests/php/monthly_import_parser_test.php
python -m unittest discover -s tests/python -p "test_*.py" -v
```