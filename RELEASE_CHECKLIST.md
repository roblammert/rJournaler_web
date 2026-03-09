# Release Checklist

## Pre-Release

1. Confirm release version in config/env (`APP_VERSION`) matches target tag v1.0.4.
2. Run migrations dry-run: `php scripts/migrate.php --dry-run`.
3. Run syntax checks:
   - `php -l public/admin-reprocess.php`
   - `php -l public/api/worker-force-kill.php`
   - `php -l public/api/worker-force-restart.php`
   - `php -l public/dashboards/status.php`
   - `php -l scripts/weather-refresh.php`
4. Run tests:
   - `php tests/php/entry_uid_smoke_test.php`
   - `php tests/php/monthly_import_parser_test.php`
   - `python -m unittest discover -s tests/python -p "test_*.py" -v`
5. Verify docs updated:
   - `README.md`
   - `CHANGELOG.md`
   - `docs/releases/v1.0.4-release-notes.md`

## Release Execution

1. Apply migrations in deployment environment: `php scripts/migrate.php`.
2. Deploy application code.
3. Start/restart web service and worker service.
4. Confirm worker is healthy and heartbeating in status dashboard.
5. Verify admin access to:
   - `dashboards/status.php`
   - `admin-reprocess.php`
   - `logviewer.php`

## Post-Release Validation

1. Login with MFA (new + existing session behavior).
2. Create/update/finish an entry and confirm queue progression to COMPLETE/FINAL.
3. Run a targeted reprocess dry-run and a small queued reprocess batch.
4. Check live log stream for Optimus/Autobot events.
5. Validate weather card updates and forecast icons.
6. Confirm no unexpected runtime artifacts are tracked by git.

## Rollback Readiness

1. Keep previous deployment artifact ready.
2. Capture current DB backup/snapshot before migration in production.
3. If rollback is needed, restore app + DB snapshot consistently.
