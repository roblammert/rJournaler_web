# rJournaler_web

Production-ready journaling web application using PHP + JavaScript + MySQL, with Python Optimus/Autobot background workers.

Current release: `1.0.0`

## Highlights in 1.0.0

- Secure auth stack: username/password, TOTP MFA, trusted devices, CSRF, audit logging.
- End-to-end entry workflow: `AUTOSAVE -> WRITTEN -> FINISHED -> IN_PROCESS -> COMPLETE/FINAL/ERROR`.
- Stage-based processing pipeline with Optimus orchestrator and Autobot workers.
- Admin operations UI: queue status, force kill/restart, stale lock reconcile, targeted reprocess.
- Full import/review flow for monthly ZIP journals with accept/deny and queue handoff.
- Weather metadata processing (Meta Group 3), weather presets, and dashboard weather widgets.
- Cross-platform operations support for Windows dev and Linux/Docker production.

## Core Pages

- `public/login.php`
- `public/index.php`
- `public/entry.php`
- `public/user-settings.php`
- `public/admin-settings.php`
- `public/admin-reprocess.php`
- `public/import-review.php`
- `public/logviewer.php`
- `public/dashboards/status.php`
- `public/dashboards/analysis-simple.php`
- `public/dashboards/analysis-deep.php`
- `public/dashboards/analysis-timeline.php`

## Quick Start

### Prereqs

- PHP with extensions: `pdo_mysql`, `mbstring`, `openssl`, `zip`
- MySQL 8+
- Python 3.11+

### Setup

1. Copy environment file.

Windows:

```powershell
Copy-Item .env.example .env
```

Linux:

```bash
cp .env.example .env
```

2. Configure DB + app settings in `.env`.
  - Set `APP_VERSION` (for UI version pill), e.g. `1.0.0`.
3. Run migrations.

```powershell
php scripts/migrate.php
```

4. Start PHP app.

```powershell
php -S localhost:8080 -t public
```

5. Start worker (separate terminal).

Windows:

```powershell
python python/worker/main.py
```

Linux/Docker:

```bash
python3 python/worker/main.py
```

## Cross-Platform Notes

- Worker control APIs support Windows and Linux kill/restart behavior.
- `scripts/weather-refresh.php` resolves Python executables for both `.venv/Scripts/python.exe` and `.venv/bin/python` with fallback.
- Status dashboard helper commands are OS-aware and path-quoted for directories with spaces.

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