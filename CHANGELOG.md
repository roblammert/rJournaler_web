
# Changelog

## [1.0.6] - 2026-03-18


# Changelog

All notable changes to this project are documented in this file.



- Removed TOTP and trusted device authentication features for simplified login and maintenance.

- App version code is now auto-generated from the version string (e.g., W010006 for 1.0.6).
- Bug fixes and codebase cleanup for authentication and entry UID logic.


### Documentation



- Updated README, installation guide, and release notes for v1.0.6.



All notable changes to this project are documented in this file.



## [1.0.5] - 2026-03-16


### Added



- Export Entries feature: Users can export their journal entries in Markdown format, supporting day, week, month, multi-month, and year ranges, with zipped output for multi-file exports.
- Editor Settings card is now a standalone card in user settings.

- Centralized `$appVersion` in `app/Support/bootstrap.php` for global access across all pages.



### Changed



- Export page UI and theming now match the main dashboard for a consistent user experience.
- Docker build and deployment instructions updated for v1.0.5, including PowerShell build script usage.


### Fixed


- Fixed dashboard JavaScript error related to wind information display.
- Improved error handling and validation for export and settings features.



### Documentation


- Updated README, installation guide, and release notes for v1.0.5.



## [1.0.4] - 2026-03-09

### Added

- Mobile Ready Editor Fix: Manual mode toggle pill/button for desktop/mobile UI, persistent mode selection via localStorage, and robust mobile UI features regardless of device detection.
- All entry, dashboard, and admin pages updated to reference v1.0.4.
- Documentation, changelog, and installation guide updated for v1.0.4.

### Changed

- All interface pills and deployment instructions now reflect 1.0.4.
- Docker compose and config files default to tag 1.0.4.
- See `docs/releases/v1.0.4-release-notes.md` for full highlights and upgrade steps.

### Fixed

- Fixed unreliable device detection for mobile UI.
- Mode pill/button now persists selection and updates all UI features.


## [1.0.3] - 2026-03-09

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
