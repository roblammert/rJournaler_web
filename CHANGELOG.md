# Changelog

All notable changes to this project are documented in this file.

## [1.0.2] - 2026-03-09

### Changed
- Editor layout and UX overhaul for mobile and desktop:
  - Responsive editor region with stable desktop sizing and foldable metadata panel.
  - Mobile fullscreen mode with viewport lock, toolbar compaction, and stats row placement.
  - Portrait mode label abbreviations and heading dropdown.
  - Metadata panel only visible for entries in COMPLETE stage.
- Updated all documentation and deployment defaults to version 1.0.2.

### Fixed
- Eliminated editor reflow/jitter in fullscreen and desktop modes.
- Fixed duplicate editor node and toolbar button issues.
- Hardened mobile/desktop mode switching and stats alignment.

### Deployment
- All Docker compose and config files now default to tag 1.0.2.
- See `docs/releases/v1.0.2-release-notes.md` for full highlights and upgrade steps.

## [1.0.1] - 2026-03-08

### Added
- Added installation guide for Windows source to Windows Docker host deployment:
	- `docs/INSTALLATION.md`

### Changed
- Updated release/documentation references from `1.0.0` to `1.0.1` across env/config/docs defaults.
- Updated image-based compose and deployment scripts to default to tag `1.0.1`.
- Updated README deployment examples and added explicit installation guide reference.

### Fixed
- Fixed Docker host deployment scripts to fail fast on Docker command errors instead of continuing after failures.
- Fixed image deployment flow to avoid unexpected registry pulls (`pull_policy: never`, `--pull never`).
- Fixed build/load behavior so packaged image builds are loaded into local Docker image store (`buildx --load`).
- Fixed image-only app runtime issue where source files were not present in container images.
- Fixed PHP container build/runtime for weather refresh by adding required Python runtime/venv support and Linux-compatible interpreter resolution.
- Fixed weather refresh loop behavior for no-cache scenarios by prioritizing immediate fetch path and improving lock-timeout handling.

## [1.0.0] - 2026-03-08

### Added
- Added full production application surface:
	- Auth and session pages (`login`, `logout`, dashboard, user/admin settings)
	- Entry editor workflow
	- Analysis dashboards
	- Queue/status monitoring dashboard
	- Import review and admin reprocess workflows
- Added secure auth stack with:
	- Password hashing
	- TOTP MFA (encrypted secret storage)
	- Trusted device support
	- CSRF protection and audit logging
- Added entry UID model and migrations (`001` through `015`) including:
	- UID-backed relational references
	- Workflow/stage metadata
	- import staging tables
	- weather metadata tables
	- orchestrator log storage
	- retention index support
- Added Optimus + Autobot runtime architecture:
	- Config-driven stage pipeline (`python/worker/pipeline_stages.json`)
	- Stage modules under `python/jobs/`
	- Prompt-driven LLM stage processing
	- Self-heal logic for stale jobs and worker state
- Added observability and admin operations:
	- Live log API/viewer (`public/api/optimus-logs.php`, `public/logviewer.php`)
	- Queue summary cards and paging
	- Admin worker controls (kill/restart/drain/reconcile)
- Added weather integrations:
	- NOAA/Open-Meteo weather ingestion support
	- user weather preset management
	- weather cache refresh/background script
	- dashboard weather cards + 5-day forecast display
- Added import tooling and pipeline helpers:
	- Monthly ZIP parser and import batch workflows
	- backfill and repair scripts
	- UID repair script for historical timestamp correction
- Added UI release version pill (`rJournaler_Web: v<major.minor.patch>`) across top-right header rows on interface pages.

### Changed
- Reworked worker runtime from basic single-loop behavior to orchestrated Optimus/Autobot multi-process execution in `python/worker/main.py`.
- Expanded status dashboard UX for troubleshooting and operator actions, including command helpers and stage-aware queue detail.
- Hardened admin reprocess flow with presets, dry-run preview, max-row controls, and batch queueing.
- Updated scripts/docs to support both Windows development and Linux/Docker production operation patterns.
- Moved deprecated correlation cleanup utility to legacy location:
	- `scripts/legacy/cleanup-correlation-jobs.php`

### Fixed
- Fixed admin reprocess runtime failures across Apply Filters, Preview Selected, and Queue Selected operations.
- Fixed SQL placeholder and malformed SQL issues in targeted reprocess queue actions.
- Fixed dry-run behavior so preview mode does not execute write-path SQL preparation.
- Added action-scoped error handling and improved failure visibility for admin reprocess actions.
- Improved worker logging with explicit claim/requeue/failure events for faster production diagnostics.
- Fixed cross-platform path handling in status helper commands for project paths containing spaces.

### Removed
- Removed Correlation Engine database objects via migration `017_remove_correlation_engine.sql`.
