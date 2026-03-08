# Python Worker

## Purpose
Background job processing for `rJournaler_Web`.

## Setup
1. Create and activate a Python 3.11+ environment.
2. Install requirements:
   - `pip install -r python/requirements.txt`
3. Ensure MySQL schema is applied from `sql/migrations/001_initial_schema.sql`.
4. Copy `.env.example` to `.env` and set DB values.

## Run
From repository root:

```powershell
python python/worker/main.py
```

## Current Status
This is a Phase 0/1 worker skeleton with queue claim/update loop and job type stubs.
