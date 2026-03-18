# rJournaler_Web Build Plan (Approval Draft)

## 1. Purpose
Build `rJournaler_Web` v1.0.6, a secure PHP + JavaScript + MySQL journaling web application with a Python processing backend. This project upgrades concepts from `C:\git-repos\Rob_Journaler` (stats, analytics, spellchecking-oriented analysis) into a multi-page web platform.

This document is the implementation plan to review and approve before coding. Updated for v1.0.6 release.

## 2. Core Requirements (from prompt)
1. Authentication security:
- Username + password + TOTP (Google Authenticator compatible).
- Optional "trust this computer" bypass for TOTP for 30 days after successful login.

2. Frontend pages:
- `login.php`
- `index.php`
- `entry.php`
- `dashboards/glance.php`
- `dashboards/analysis-simple.php`
- `dashboards/analysis-deep.php`
- `dashboards/status.php`

3. Python backend processing:
- Triggerable from PHP.
- File ingestion.
- Entry processing.
- Worker queues and worker tracking.
- Local LLM integration for deeper analysis.

## 3. Proposed High-Level Architecture
### 3.1 Stack
- Web: PHP 8.3+, vanilla JS (or lightweight utility libraries only where needed), HTML/CSS.
- Database: MySQL 8+.
- Python services: Python 3.11+ worker process.
- Web server: Apache or Nginx + PHP-FPM.

### 3.2 Application components
- `public/` PHP entry points and assets.
- `app/` PHP domain logic (auth, entries, dashboards, services).
- `config/` environment and app settings.
- `python/` worker service, queue processor, analysis modules.
- `sql/` schema and migration scripts.
- `storage/` optional file ingest area, logs, exports.

### 3.3 Integration model (PHP <-> Python)
- PHP writes jobs into MySQL queue table (`worker_jobs`).
- Python worker polls queue, claims jobs, processes, writes results/status/errors back.
- `dashboards/status.php` reads queue and worker heartbeat/service data.

This avoids fragile direct shell execution from request/response flows and supports retries.

## 4. Security Design
### 4.1 Auth and sessions
- Password hashing: `password_hash(..., PASSWORD_ARGON2ID)`.
- Session security:
- Regenerate session ID on login and privilege changes.
- `HttpOnly`, `Secure`, `SameSite=Strict` cookies.
- Strict session timeout and inactivity timeout.

### 4.2 MFA (TOTP)
- Per-user TOTP secret, encrypted at rest.
- RFC 6238-compatible codes (Google Authenticator interoperability).
- TOTP required at login unless trusted-device token is valid.

### 4.3 Trusted device (30 days)
- "Trust this computer" sets a random token stored in secure cookie.
- Only store hashed token in DB (`trusted_devices.token_hash`).
- Token bound to user, expiry timestamp (+30 days), user agent fingerprint metadata.
- Revocable from user security settings screen.

### 4.4 Baseline web hardening
- CSRF tokens on all state-changing requests.
- Output escaping everywhere (`htmlspecialchars`).
- Prepared statements only.
- Login throttling + lockout policy.
- Audit logging for auth events and security changes.

## 5. Data Model (Initial)
### 5.1 Auth + security tables
- `users`
- `id`, `username`, `email`, `password_hash`, `totp_secret_encrypted`, `is_active`, timestamps.
- `trusted_devices`
- `id`, `user_id`, `token_hash`, `device_name`, `user_agent_hash`, `expires_at`, `last_used_at`, timestamps.
- `auth_attempts`
- for throttling/abuse detection.
- `audit_log`
- security and major action trail.

### 5.2 Journal domain tables
- `journal_entries`
- `id`, `user_id`, `entry_date`, `title`, `content_raw`, `content_html` (optional), `word_count`, timestamps.
- `entry_tags`
- optional tags/categories.

### 5.3 Analytics + workers tables
- `entry_metrics`
- precomputed stats per entry (sentiment/readability/spelling/etc).
- `worker_jobs`
- `id`, `job_type`, `payload_json`, `status`, `priority`, `attempt_count`, `run_after`, `locked_at`, `locked_by`, `error_message`, timestamps.
- `worker_runs`
- execution history and diagnostics.
- `service_health`
- latest service checks for status dashboard.

## 6. Page-by-Page Plan
### 6.1 `login.php`
- Username/password form.
- Step-up TOTP challenge screen if credentials valid and trusted-device absent.
- "Trust this computer for 30 days" option on successful TOTP.

### 6.2 `index.php`
- Authenticated landing page.
- Quick cards: recent entries, words this week/month, queue status summary, latest analysis snapshot.

### 6.3 `entry.php`
- Create/edit/view journal entries.
- Real-time JS features:
- word count.
- estimated read time.
- spellcheck hints (browser + backend-verified optional).
- Auto-save draft endpoint (debounced).
- Save action queues background metric analysis job.

### 6.4 `dashboards/glance.php`
- Date filters:
- absolute range (start/end).
- relative presets (this week/month/year/all time/custom rolling windows).
- Charts: entry volume, word trends, sentiment trend.

### 6.5 `dashboards/analysis-simple.php`
- High-level KPIs by date range.
- Top recurring terms/topics.
- Basic sentiment and readability summaries.

### 6.6 `dashboards/analysis-deep.php`
- Detailed analysis view.
- Pattern timelines, emotional signal evolution, term clusters.
- Local LLM generated summaries/insights with explicit "generated" labeling.

### 6.7 `dashboards/status.php`
- Worker queue backlog and processing rates.
- Worker heartbeat and last run outcomes.
- Dependency checks: DB connectivity, Python worker running, LLM service reachable.

## 7. Python Backend Plan
### 7.1 Worker service
- Long-running process with configurable poll interval.
- Claims jobs atomically (`UPDATE ... WHERE status='queued' ... LIMIT 1`).
- Supports retries with capped backoff.
- Marks fatal jobs as failed with detailed errors.

### 7.2 Job types (initial)
- `entry_metrics_rebuild`
- `entry_metrics_single`
- `llm_summary_generate`
- `file_ingest`
- `service_health_check`

### 7.3 Reuse from legacy `Rob_Journaler`
- Adapt stats concepts from `core/stats_collector.py`.
- Adapt analytics logic from `core/analytics_collector.py`.
- Keep improved tokenization/emotion/sentiment/readability but redesign for DB-first storage.

## 8. Delivery Phases
### Current Status Snapshot
- Phase 0: Completed
- Phase 1: Completed
- Phase 2: Completed
- Phase 3: Partially completed (worker lifecycle and status data paths active)
- Phase 4+: Planned

### Phase 0 - Foundation and environment
- Repository scaffolding and folder structure.
- `.env` strategy and secure config loading.
- MySQL schema v1 and migration tooling.
- Basic CI checks (lint/unit tests placeholder).

### Phase 1 - Authentication + security baseline
- User model + password auth.
- TOTP enrollment and verification.
- Trusted device token flow.
- Session hardening + CSRF + rate limiting.

### Phase 2 - Journal entry MVP
- `index.php`, `entry.php` basic CRUD.
- Real-time word count and draft autosave.
- Queue write on save for metrics processing.

### Phase 3 - Python workers + status dashboard
- Worker daemon and queue lifecycle.
- `dashboards/status.php` with queue and health metrics.
- Health check jobs and service diagnostics.

Implemented so far:
- Worker job claiming with retry/backoff and capped attempts
- Stale lock recovery for jobs stuck in `processing`
- `entry_metrics_single` and `entry_metrics_rebuild` handler paths (UID keyed)
- `service_health_check` writes service status rows used by status dashboard

### Phase 4 - Glance and simple analysis dashboards
- Date filtering API.
- `dashboards/glance.php` charts.
- `dashboards/analysis-simple.php` summary analytics.

### Phase 5 - Deep analysis + local LLM
- `dashboards/analysis-deep.php`.
- LLM integration and caching of generated insights.
- Safety guardrails and transparency labels.

### Phase 6 - Hardening and release prep
- Security audit checklist.
- Performance tuning (indexes, query profiling, worker throughput).
- Backup/restore and deployment runbook.

## 9. Testing Strategy
- PHP unit tests for auth/session/security logic.
- Integration tests for login + TOTP + trusted device.
- API tests for entries and date-filtered dashboard queries.
- Python unit tests for worker handlers and analytics modules.
- End-to-end smoke tests for core user journey.

## 10. Definition of Done (MVP)
- User can register/login with password + TOTP.
- Trusted device flow bypasses TOTP for 30 days and can be revoked.
- User can create/edit journal entries with real-time word count.
- Saving entries enqueues and processes background analysis jobs.
- Glance/simple dashboards show date-filtered results.
- Status dashboard shows queue and service health.
- Core security controls implemented and validated.

## 11. Key Risks and Mitigations
- Risk: insecure trusted device implementation.
- Mitigation: random high-entropy token, hashed at rest, secure cookie flags, strict expiry/revocation.

- Risk: worker race conditions and duplicate processing.
- Mitigation: atomic claim queries, idempotent handlers, retries with attempt caps.

- Risk: LLM latency or downtime.
- Mitigation: async jobs, timeout/circuit breaker, cached last-good outputs.

- Risk: dashboard query slowness on large datasets.
- Mitigation: precomputed metrics and indexed aggregate tables.

## 12. Collaboration and Approval Gates
### Gate A (Now): Approve architecture + phased plan
- Confirm stack versions.
- Confirm DB-first queue model.
- Confirm initial analytics scope.

### Gate B: Approve schema and auth flow diagrams
- Before implementing full auth.

### Gate C: Approve MVP UI wireframes/page contracts
- Before broad frontend implementation.

### Gate D: Approve LLM/deep-analysis behavior
- Before enabling local model outputs.

## 13. Immediate Next Build Steps After Approval
1. Create initial project structure and environment config templates.
2. Create SQL schema v1 with auth + entries + worker tables.
3. Implement Phase 1 auth (password + TOTP + trusted devices).
4. Add basic `index.php` and `entry.php` with authenticated routing.
5. Stand up Python worker skeleton and queue processor.

## 14. Open Decisions Needed From You
1. Auth scope:
- Single-user private instance or multi-user from day one?

2. Deployment target:
- Local-only LAN app, VPS-hosted, or both?

3. Local LLM integration:
- Preferred runtime (Ollama, llama.cpp server, LM Studio API, other)?

4. UI framework preference:
- Pure vanilla JS or allow lightweight chart/UI libraries (e.g., Chart.js)?

5. Entry content format:
- Plain text, Markdown, or both?

6. Legacy data migration:
- Should we import existing `YYYY/YYYY-MM.txt` files into MySQL during early phases?
