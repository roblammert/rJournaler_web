from __future__ import annotations

import hashlib
import http.client
import json
import os
import random
import re
import socket
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from multiprocessing import Process

import mysql.connector
from textstat import textstat


JOBS_MODULE_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'jobs'))
if JOBS_MODULE_DIR not in sys.path:
    sys.path.insert(0, JOBS_MODULE_DIR)

try:
    from job_meta_group_0 import run_stage as run_meta_group_0_stage
    from job_meta_group_1 import run_stage as run_meta_group_1_stage
    from job_meta_group_2_llm import run_stage as run_meta_group_2_llm_stage
    from job_meta_group_3_weather import run_stage as run_meta_group_3_weather_stage
    from job_metrics_finalize import run_stage as run_metrics_finalize_stage
except Exception:
    run_meta_group_0_stage = None
    run_meta_group_1_stage = None
    run_meta_group_2_llm_stage = None
    run_meta_group_3_weather_stage = None
    run_metrics_finalize_stage = None


MAX_ATTEMPTS = 5
MAX_OLLAMA_UNAVAILABLE_ATTEMPTS = 12
STALE_LOCK_MINUTES = 10
OLLAMA_RETRY_MESSAGE = 'Waiting "Ollama unavailable at this time, will retry and process when available"'
NOAA_RATE_LIMIT_SECONDS = 5.0
NOAA_RATE_LIMIT_LOCK_NAME = 'rjournaler_web.noaa.rate_limit'
NOAA_RATE_LIMIT_SETTING_KEY = 'processing.noaa.last_touch_epoch'

DEFAULT_ENTRY_WEATHER_LOCATION = {
    'key': 'new_richmond_wi',
    'label': 'New Richmond, WI, US',
    'city': 'New Richmond',
    'state': 'WI',
    'zip': '54017',
    'country': 'US',
}

DEFAULT_PIPELINE_STAGES = [
    {'id': 'meta_group_0', 'label': 'Meta Group 0', 'type': 'db'},
    {'id': 'meta_group_1', 'label': 'Meta Group 1', 'type': 'db'},
    {'id': 'meta_group_2_llm', 'label': 'Meta Group 2 (LLM)', 'type': 'llm', 'prompt_file': 'meta_group_2_prompt.txt'},
    {'id': 'meta_group_3_weather', 'label': 'Meta Group 3 (Weather)', 'type': 'db'},
    {'id': 'metrics_finalize', 'label': 'Metrics Finalize', 'type': 'db'},
]


class OllamaUnavailableError(RuntimeError):
    pass


@dataclass
class RuntimeConfig:
    db_host: str
    db_port: int
    db_name: str
    db_user: str
    db_password: str
    poll_seconds: float
    ollama_url: str
    ollama_model: str
    ollama_timeout_seconds: float
    ollama_required: bool
    ollama_retry_seconds: int
    queue_complete_retention_hours: float
    queue_failed_retention_hours: float
    orchestrator_log_retention_hours: float
    audit_log_retention_days: int
    auto_finish_hour_local: int
    pipeline_config_path: str
    prompt_dir: str
    optimus_max_autobots: int
    autobot_max_per_type_default: int
    autobot_idle_lifetime_minutes: float
    autobot_limits: dict[str, int]


def load_env_file() -> None:
    env_path = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '.env'))
    if not os.path.isfile(env_path):
        return

    with open(env_path, 'r', encoding='utf-8') as handle:
        for line in handle:
            line = line.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            key, value = line.split('=', 1)
            os.environ.setdefault(key.strip(), value.strip())


def get_connection(cfg: RuntimeConfig):
    return mysql.connector.connect(
        host=cfg.db_host,
        port=cfg.db_port,
        user=cfg.db_user,
        password=cfg.db_password,
        database=cfg.db_name,
        autocommit=True,
    )


def normalize_ollama_generate_url(raw_url: str) -> str:
    value = str(raw_url or '').strip()
    if value == '':
        return 'http://127.0.0.1:11434/api/generate'

    if '://' not in value:
        value = 'http://' + value

    parsed = urllib.parse.urlparse(value)
    scheme = parsed.scheme or 'http'
    netloc = parsed.netloc
    path = (parsed.path or '').strip()

    # Handle values like "127.0.0.1:11434" after parsing fallback.
    if netloc == '' and parsed.path:
        netloc = parsed.path
        path = ''

    if netloc == '':
        netloc = '127.0.0.1:11434'

    path = '/' + path.lstrip('/') if path != '' else ''
    if path == '' or path == '/':
        path = '/api/generate'
    elif not path.endswith('/api/generate'):
        path = path.rstrip('/') + '/api/generate'

    return urllib.parse.urlunparse((scheme, netloc, path, '', '', ''))


def runtime_from_env() -> RuntimeConfig:
    load_env_file()
    worker_dir = os.path.dirname(__file__)
    ollama_url_raw = os.getenv('OLLAMA_URL', 'http://127.0.0.1:11434/api/generate')
    ollama_url = normalize_ollama_generate_url(ollama_url_raw)
    return RuntimeConfig(
        db_host=os.getenv('DB_HOST', '127.0.0.1'),
        db_port=int(os.getenv('DB_PORT', '3306')),
        db_name=os.getenv('DB_NAME', 'rjournaler_web'),
        db_user=os.getenv('DB_USER', 'root'),
        db_password=os.getenv('DB_PASSWORD', ''),
        poll_seconds=max(1.0, float(os.getenv('WORKER_POLL_SECONDS', '3'))),
        ollama_url=ollama_url,
        ollama_model=os.getenv('OLLAMA_MODEL', 'llama3.1'),
        ollama_timeout_seconds=max(5.0, float(os.getenv('OLLAMA_TIMEOUT_SECONDS', '45'))),
        ollama_required=str(os.getenv('OLLAMA_REQUIRED', 'false')).strip().lower() in {'1', 'true', 'yes', 'on'},
        ollama_retry_seconds=max(10, int(os.getenv('OLLAMA_RETRY_SECONDS', '120'))),
        queue_complete_retention_hours=max(0.0, float(os.getenv('QUEUE_COMPLETE_RETENTION_HOURS', '168'))),
        queue_failed_retention_hours=max(0.0, float(os.getenv('QUEUE_FAILED_RETENTION_HOURS', '168'))),
        orchestrator_log_retention_hours=max(0.25, float(os.getenv('ORCHESTRATOR_LOG_RETENTION_HOURS', '8'))),
        audit_log_retention_days=max(1, int(os.getenv('AUDIT_LOG_RETENTION_DAYS', '90'))),
        auto_finish_hour_local=max(0, min(23, int(os.getenv('AUTO_FINISH_HOUR_LOCAL', '1')))),
        pipeline_config_path=os.getenv('PIPELINE_CONFIG_PATH', os.path.join(worker_dir, 'pipeline_stages.json')),
        prompt_dir=os.getenv('PIPELINE_PROMPT_DIR', os.path.join(worker_dir, 'prompts')),
        optimus_max_autobots=max(1, int(os.getenv('OPTIMUS_MAX_AUTOBOTS', '4'))),
        autobot_max_per_type_default=max(1, int(os.getenv('AUTOBOT_MAX_PER_TYPE_DEFAULT', '1'))),
        autobot_idle_lifetime_minutes=max(0.5, float(os.getenv('AUTOBOT_IDLE_LIFETIME_MINUTES', '5'))),
        autobot_limits={},
    )


def load_pipeline_stages(cfg: RuntimeConfig) -> list[dict]:
    if not os.path.isfile(cfg.pipeline_config_path):
        return DEFAULT_PIPELINE_STAGES

    try:
        with open(cfg.pipeline_config_path, 'r', encoding='utf-8') as handle:
            data = json.load(handle)
    except Exception:
        return DEFAULT_PIPELINE_STAGES

    if not isinstance(data, list):
        return DEFAULT_PIPELINE_STAGES

    normalized: list[dict] = []
    for raw in data:
        if not isinstance(raw, dict):
            continue
        stage_id = str(raw.get('id', '')).strip()
        label = str(raw.get('label', stage_id)).strip()
        stage_type = str(raw.get('type', 'db')).strip().lower()
        if stage_id == '' or label == '':
            continue
        stage = {'id': stage_id, 'label': label, 'type': stage_type}
        prompt_file = raw.get('prompt_file')
        if isinstance(prompt_file, str) and prompt_file.strip() != '':
            stage['prompt_file'] = prompt_file.strip()
        normalized.append(stage)

    return normalized if normalized else DEFAULT_PIPELINE_STAGES


def apply_app_settings_overrides(cfg: RuntimeConfig) -> RuntimeConfig:
    conn = None
    try:
        conn = get_connection(cfg)
        cursor = conn.cursor(dictionary=True)
        cursor.execute('SELECT setting_key, setting_value FROM app_settings')
        rows = cursor.fetchall() or []

        autobot_limits: dict[str, int] = dict(cfg.autobot_limits)
        for row in rows:
            if not isinstance(row, dict):
                continue
            key = str(row.get('setting_key') or '').strip()
            value = str(row.get('setting_value') or '').strip()
            if key == '':
                continue

            if key == 'processing.ollama_url' and value != '':
                cfg.ollama_url = normalize_ollama_generate_url(value)
            elif key == 'processing.ollama_model' and value != '':
                cfg.ollama_model = value
            elif key == 'processing.ollama_retry_seconds':
                cfg.ollama_retry_seconds = max(10, int(float(value or '0')))
            elif key == 'processing.ollama_timeout_seconds':
                cfg.ollama_timeout_seconds = max(1.0, float(value or '0'))
            elif key == 'processing.queue_complete_retention_hours':
                cfg.queue_complete_retention_hours = max(0.25, float(value or '0'))
            elif key == 'processing.queue_failed_retention_hours':
                cfg.queue_failed_retention_hours = max(0.25, float(value or '0'))
            elif key == 'processing.orchestrator_log_retention_hours':
                cfg.orchestrator_log_retention_hours = max(0.25, float(value or '0'))
            elif key == 'processing.audit_log_retention_days':
                cfg.audit_log_retention_days = max(1, int(float(value or '0')))
            elif key == 'processing.auto_finish_hour_local':
                cfg.auto_finish_hour_local = max(0, min(23, int(float(value or '0'))))
            elif key == 'processing.optimus_max_autobots':
                cfg.optimus_max_autobots = max(1, int(float(value or '0')))
            elif key == 'processing.autobot_max_per_type_default':
                cfg.autobot_max_per_type_default = max(1, int(float(value or '0')))
            elif key == 'processing.autobot_idle_lifetime_minutes':
                cfg.autobot_idle_lifetime_minutes = max(0.5, float(value or '0'))
            elif key.startswith('processing.autobot_limit.'):
                stage_id = key[len('processing.autobot_limit.'):].strip()
                if stage_id != '':
                    autobot_limits[stage_id] = max(1, int(float(value or '0')))

        cfg.autobot_limits = autobot_limits
        # Re-normalize in case the active URL came from any override path.
        cfg.ollama_url = normalize_ollama_generate_url(cfg.ollama_url)
    except Exception:
        # Settings table may not be available yet.
        pass
    finally:
        if conn is not None and conn.is_connected():
            conn.close()

    return cfg


def heartbeat(cursor, worker_name: str, status: str = 'running', notes: str | None = None) -> None:
    now = datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
    note_value = notes if isinstance(notes, str) else None
    cursor.execute(
        """
        UPDATE worker_runs
        SET heartbeat_at = %s,
            status = %s,
            notes = %s
        WHERE worker_name = %s
        ORDER BY id DESC
        LIMIT 1
        """,
        (now, status, note_value, worker_name),
    )
    if cursor.rowcount > 0:
        return

    cursor.execute(
        """
        INSERT INTO worker_runs (worker_name, started_at, heartbeat_at, status, notes)
        VALUES (%s, %s, %s, %s, %s)
        """,
        (worker_name, now, now, status, note_value),
    )


def log_event(cfg: RuntimeConfig, level: str, source: str, message: str, context: dict | None = None) -> None:
    ts = datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
    upper = level.upper()[:16]
    print(f"[{ts}] [{upper}] [{source}] {message}")

    conn = None
    try:
        conn = get_connection(cfg)
        cursor = conn.cursor()
        cursor.execute(
            """
            INSERT INTO orchestrator_logs (level, source, message, context_json, created_at)
            VALUES (%s, %s, %s, %s, UTC_TIMESTAMP())
            """,
            (
                upper,
                source[:64],
                message[:500],
                json.dumps(context, separators=(',', ':')) if isinstance(context, dict) else None,
            ),
        )
    except Exception:
        pass
    finally:
        if conn is not None and conn.is_connected():
            conn.close()


def acquire_optimus_lock(cfg: RuntimeConfig):
    conn = None
    try:
        conn = get_connection(cfg)
        cursor = conn.cursor()
        cursor.execute("SELECT GET_LOCK(%s, 0)", ('rjournaler_web.optimus',))
        row = cursor.fetchone()
        if row is None or int(row[0]) != 1:
            if conn.is_connected():
                conn.close()
            return None
        return conn
    except Exception:
        if conn is not None and conn.is_connected():
            conn.close()
        return None


def release_optimus_lock(lock_conn) -> None:
    if lock_conn is None:
        return
    try:
        cursor = lock_conn.cursor()
        cursor.execute("SELECT RELEASE_LOCK(%s)", ('rjournaler_web.optimus',))
        cursor.fetchone()
    except Exception:
        pass
    finally:
        if lock_conn.is_connected():
            lock_conn.close()


def is_autobot_drain_requested(cursor, worker_name: str) -> bool:
    key = f'processing.autobot_drain.{worker_name}'
    cursor.execute(
        """
        SELECT setting_value
        FROM app_settings
        WHERE setting_key = %s
        LIMIT 1
        """,
        (key,),
    )
    row = cursor.fetchone()
    if row is None:
        return False
    value = str(row[0] if not isinstance(row, dict) else row.get('setting_value', '')).strip().lower()
    return value in {'1', 'true', 'yes', 'on'}


def clear_autobot_drain_request(cfg: RuntimeConfig, worker_name: str) -> None:
    key = f'processing.autobot_drain.{worker_name}'
    conn = None
    try:
        conn = get_connection(cfg)
        cursor = conn.cursor()
        cursor.execute(
            """
            DELETE FROM app_settings
            WHERE setting_key = %s
            """,
            (key,),
        )
    except Exception:
        pass
    finally:
        if conn is not None and conn.is_connected():
            conn.close()


def request_autobot_drain(cfg: RuntimeConfig, worker_name: str) -> None:
    key = f'processing.autobot_drain.{worker_name}'
    conn = None
    try:
        conn = get_connection(cfg)
        cursor = conn.cursor()
        cursor.execute(
            """
            INSERT INTO app_settings (setting_key, setting_value, updated_by_user_id, updated_at)
            VALUES (%s, %s, NULL, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by_user_id = NULL,
                updated_at = UTC_TIMESTAMP()
            """,
            (key, '1'),
        )
    except Exception:
        pass
    finally:
        if conn is not None and conn.is_connected():
            conn.close()


def release_stale_processing_jobs(cursor) -> None:
    stale_before = (datetime.now(timezone.utc) - timedelta(minutes=STALE_LOCK_MINUTES)).strftime('%Y-%m-%d %H:%M:%S')
    cursor.execute(
        """
        UPDATE worker_jobs
        SET status = 'queued',
            locked_at = NULL,
            locked_by = NULL,
            run_after = UTC_TIMESTAMP(),
            error_message = COALESCE(error_message, 'Recovered stale processing lock')
        WHERE status = 'processing'
          AND locked_at IS NOT NULL
          AND locked_at < %s
        """,
        (stale_before,),
    )


def self_heal_orphan_autobot_processing_jobs(cursor, cfg: RuntimeConfig) -> int:
    lock_grace_seconds = autobot_processing_lock_grace_seconds(cfg)
    heartbeat_seconds = lock_grace_seconds
    cursor.execute(
        """
        UPDATE worker_jobs wj
        LEFT JOIN (
            SELECT wr.worker_name, wr.status, wr.heartbeat_at
            FROM worker_runs wr
            INNER JOIN (
                SELECT worker_name, MAX(id) AS max_id
                FROM worker_runs
                WHERE worker_name LIKE 'Autobot-%'
                GROUP BY worker_name
            ) latest ON latest.max_id = wr.id
        ) aw ON aw.worker_name = wj.locked_by
        SET wj.status = 'queued',
            wj.locked_at = NULL,
            wj.locked_by = NULL,
            wj.run_after = UTC_TIMESTAMP(),
            wj.queue_comment = LEFT(CONCAT(COALESCE(wj.queue_comment, ''), ' | Self-healed orphan lock ', UTC_TIMESTAMP()), 240),
            wj.error_message = COALESCE(wj.error_message, 'Self-healed orphan Autobot lock')
        WHERE wj.status = 'processing'
          AND wj.locked_by LIKE 'Autobot-%'
          AND wj.locked_at IS NOT NULL
          AND wj.locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %s SECOND)
          AND (
                aw.worker_name IS NULL
                OR aw.status <> 'running'
                OR aw.heartbeat_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %s SECOND)
          )
        """,
        (lock_grace_seconds, heartbeat_seconds),
    )
    return max(0, int(cursor.rowcount))


def self_heal_stale_autobot_running_rows(cursor, cfg: RuntimeConfig) -> int:
    stale_seconds = max(autobot_heartbeat_threshold_seconds(cfg) * 4, 60)
    cursor.execute(
        """
        UPDATE worker_runs wr
        INNER JOIN (
            SELECT worker_name, MAX(id) AS max_id
            FROM worker_runs
            WHERE worker_name LIKE 'Autobot-%'
            GROUP BY worker_name
        ) latest ON latest.max_id = wr.id
        LEFT JOIN worker_jobs wj
            ON wj.status = 'processing'
           AND wj.locked_by = wr.worker_name
        SET wr.status = 'stopped',
            wr.notes = LEFT(CONCAT('self-heal stale run row | ', COALESCE(wr.notes, '')), 240)
        WHERE wr.worker_name LIKE 'Autobot-%'
          AND wr.status = 'running'
          AND wr.heartbeat_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %s SECOND)
          AND wj.id IS NULL
        """,
        (stale_seconds,),
    )
    return max(0, int(cursor.rowcount))


def self_heal_stale_autobot_drain_keys(cursor, cfg: RuntimeConfig) -> int:
    heartbeat_seconds = max(autobot_heartbeat_threshold_seconds(cfg), 8)
    drain_grace_seconds = max(heartbeat_seconds * 6, 120)
    drain_key_prefix = 'processing.autobot_drain.'
    drain_key_prefix_offset = len(drain_key_prefix) + 1
    cursor.execute(
        """
        DELETE s
        FROM app_settings s
        LEFT JOIN (
            SELECT wr.worker_name
            FROM worker_runs wr
            INNER JOIN (
                SELECT worker_name, MAX(id) AS max_id
                FROM worker_runs
                WHERE worker_name LIKE 'Autobot-%'
                GROUP BY worker_name
            ) latest ON latest.max_id = wr.id
            WHERE wr.worker_name LIKE 'Autobot-%'
              AND (
                    (wr.status = 'running' AND wr.heartbeat_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %s SECOND))
                    OR EXISTS(
                        SELECT 1
                        FROM worker_jobs wj
                        WHERE wj.status = 'processing'
                          AND wj.locked_by = wr.worker_name
                    )
              )
        ) active ON active.worker_name = SUBSTRING(s.setting_key, %s)
        WHERE s.setting_key LIKE 'processing.autobot_drain.%'
          AND active.worker_name IS NULL
          AND s.updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %s SECOND)
        """,
        (heartbeat_seconds, drain_key_prefix_offset, drain_grace_seconds),
    )
    return max(0, int(cursor.rowcount))


def self_heal_autobot_runtime_state(cursor, cfg: RuntimeConfig) -> dict[str, int]:
    healed_orphan_locks = self_heal_orphan_autobot_processing_jobs(cursor, cfg)
    healed_stale_rows = self_heal_stale_autobot_running_rows(cursor, cfg)
    healed_drain_keys = self_heal_stale_autobot_drain_keys(cursor, cfg)
    return {
        'orphan_locks': healed_orphan_locks,
        'stale_run_rows': healed_stale_rows,
        'stale_drain_keys': healed_drain_keys,
    }


def clean_old_completed_jobs(cursor, retention_hours: float) -> int:
    if retention_hours <= 0:
        return 0
    cutoff = (datetime.now(timezone.utc) - timedelta(hours=retention_hours)).strftime('%Y-%m-%d %H:%M:%S')
    cursor.execute(
        """
        DELETE FROM worker_jobs
        WHERE status = 'completed'
          AND completed_at IS NOT NULL
          AND completed_at < %s
        """,
        (cutoff,),
    )
    return max(0, int(cursor.rowcount))


def clean_old_failed_jobs(cursor, retention_hours: float) -> int:
    if retention_hours <= 0:
        return 0
    cutoff = (datetime.now(timezone.utc) - timedelta(hours=retention_hours)).strftime('%Y-%m-%d %H:%M:%S')
    cursor.execute(
        """
        DELETE FROM worker_jobs
        WHERE status = 'failed'
          AND completed_at IS NOT NULL
          AND completed_at < %s
        """,
        (cutoff,),
    )
    return max(0, int(cursor.rowcount))


def clean_old_orchestrator_logs(cursor, retention_hours: float) -> int:
    if retention_hours <= 0:
        return 0
    cutoff = (datetime.now(timezone.utc) - timedelta(hours=retention_hours)).strftime('%Y-%m-%d %H:%M:%S')
    cursor.execute(
        """
        UPDATE orchestrator_logs
        SET is_old = 1
        WHERE is_old = 0
          AND created_at < %s
        """,
        (cutoff,),
    )
    return max(0, int(cursor.rowcount))


def clean_old_audit_logs(cursor, retention_days: int) -> int:
    if retention_days <= 0:
        return 0
    cutoff = (datetime.now(timezone.utc) - timedelta(days=retention_days)).strftime('%Y-%m-%d %H:%M:%S')
    cursor.execute(
        """
        DELETE FROM audit_log
        WHERE created_at < %s
        """,
        (cutoff,),
    )
    return max(0, int(cursor.rowcount))


def queue_auto_finish_entries(cursor, cfg: RuntimeConfig) -> None:
    now_local = datetime.now()
    if now_local.hour < cfg.auto_finish_hour_local:
        return

    today_local = now_local.strftime('%Y-%m-%d')
    cursor.execute(
        """
        SELECT entry_uid, user_id
        FROM journal_entries
        WHERE entry_date < %s
          AND workflow_stage IN ('AUTOSAVE', 'WRITTEN')
          AND body_locked = 0
        ORDER BY updated_at ASC
        LIMIT 50
        """,
        (today_local,),
    )
    candidates = cursor.fetchall() or []
    for entry_uid, user_id in candidates:
        cursor.execute(
            """
            SELECT COUNT(*)
            FROM worker_jobs
            WHERE entry_uid = %s
              AND job_type = 'entry_process_pipeline'
              AND status IN ('queued', 'processing')
            """,
            (entry_uid,),
        )
        if int(cursor.fetchone()[0]) > 0:
            continue

        mark_entry_stage(cursor, str(entry_uid), 'FINISHED', body_locked=1)
        cursor.execute(
            """
            INSERT INTO worker_jobs
                (job_type, entry_uid, submitter, stage_label, queue_comment, payload_json, status, priority, attempt_count, run_after, submitted_at)
            VALUES
                (%s, %s, %s, %s, %s, %s, 'queued', %s, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            """,
            (
                'entry_process_pipeline',
                entry_uid,
                'AUTO',
                'Queued',
                'Auto-finish cutoff reached',
                json.dumps({
                    'entry_uid': entry_uid,
                    'user_id': int(user_id),
                    'source': 'auto_finish',
                    'pipeline': {
                        'completed': [],
                        'remaining_labels': ['Meta Group 0', 'Meta Group 1', 'Meta Group 2 (LLM)', 'Meta Group 3 (Weather)', 'Metrics Finalize'],
                    },
                }, separators=(',', ':')),
                45,
            ),
        )


def mark_entry_stage(cursor, entry_uid: str, stage: str, body_locked: int | None = None) -> None:
    if body_locked is None:
        cursor.execute(
            """
            UPDATE journal_entries
            SET workflow_stage = %s,
                stage_updated_at = UTC_TIMESTAMP(),
                updated_at = UTC_TIMESTAMP()
            WHERE entry_uid = %s
            """,
            (stage, entry_uid),
        )
        return

    cursor.execute(
        """
        UPDATE journal_entries
        SET workflow_stage = %s,
            body_locked = %s,
            stage_updated_at = UTC_TIMESTAMP(),
            updated_at = UTC_TIMESTAMP()
        WHERE entry_uid = %s
        """,
        (stage, body_locked, entry_uid),
    )


def ensure_pipeline_state(payload: dict, stages: list[dict]) -> dict:
    stage_order = [str(stage['id']) for stage in stages]
    label_map = {str(stage['id']): str(stage['label']) for stage in stages}

    if not isinstance(payload.get('pipeline'), dict):
        payload['pipeline'] = {}

    pipeline = payload['pipeline']
    completed = pipeline.get('completed')
    if not isinstance(completed, list):
        completed = []

    completed_ids = [str(value) for value in completed if str(value) in stage_order]
    remaining_ids = [sid for sid in stage_order if sid not in completed_ids]

    pipeline['completed'] = completed_ids
    pipeline['remaining'] = remaining_ids
    pipeline['remaining_labels'] = [label_map[sid] for sid in remaining_ids]
    pipeline['stages'] = [{'id': sid, 'label': label_map[sid]} for sid in stage_order]
    return payload


def mark_stage_completed(payload: dict, stage_id: str, stages: list[dict]) -> dict:
    payload = ensure_pipeline_state(payload, stages)
    pipeline = payload['pipeline']
    completed = list(pipeline.get('completed', []))
    if stage_id not in completed:
        completed.append(stage_id)
        pipeline['completed'] = completed
    return ensure_pipeline_state(payload, stages)


def legacy_stage_label_for_id(stage_id: str) -> str:
    mapping = {
        'meta_group_0': 'Meta Group 0',
        'meta_group_1': 'Meta Group 1',
        'meta_group_2_llm': 'Meta Group 2 (LLM)',
        'meta_group_3_weather': 'Meta Group 3 (Weather)',
        'metrics_finalize': 'Metrics Finalize',
    }
    return mapping.get(stage_id, '')


def claim_pipeline_job_for_stage(cursor, worker_name: str, stage_id: str, stage_label: str):
    now = datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
    legacy_label = legacy_stage_label_for_id(stage_id)
    cursor.execute(
        """
        UPDATE worker_jobs
        SET status = 'processing',
            stage_label = %s,
            queue_comment = %s,
            locked_at = %s,
            locked_by = %s,
            attempt_count = attempt_count + 1
        WHERE id = (
            SELECT id
            FROM (
                SELECT id
                FROM worker_jobs
                WHERE job_type = 'entry_process_pipeline'
                  AND status = 'queued'
                  AND run_after <= %s
                  AND (
                    JSON_SEARCH(payload_json, 'one', %s, NULL, '$.pipeline.remaining[*]') IS NOT NULL
                    OR (
                        %s <> ''
                        AND JSON_SEARCH(payload_json, 'one', %s, NULL, '$.pipeline.remaining_labels[*]') IS NOT NULL
                    )
                      )
                ORDER BY priority ASC, id ASC
                LIMIT 1
            ) AS candidate
        )
        """,
        (
            f'Processing {stage_label}'[:120],
            f'Claimed by {worker_name}'[:240],
            now,
            worker_name,
            now,
            stage_id,
            legacy_label,
            legacy_label,
        ),
    )

    if cursor.rowcount <= 0:
        return None

    cursor.execute(
        """
        SELECT id, entry_uid, payload_json, attempt_count
        FROM worker_jobs
        WHERE status = 'processing'
          AND locked_by = %s
          AND locked_at = %s
        ORDER BY id DESC
        LIMIT 1
        """,
        (worker_name, now),
    )
    row = cursor.fetchone()
    if row is None:
        return None

    job_id, entry_uid, payload_json, attempt_count = row
    payload = payload_json if isinstance(payload_json, dict) else json.loads(payload_json)
    return {
        'id': int(job_id),
        'entry_uid': str(entry_uid),
        'payload': payload,
        'attempt_count': int(attempt_count),
    }


def complete_job(cursor, job_id: int) -> None:
    cursor.execute(
        """
        UPDATE worker_jobs
        SET status = 'completed',
            stage_label = 'Completed',
            queue_comment = 'Pipeline complete',
            completed_at = UTC_TIMESTAMP(),
            error_message = NULL
        WHERE id = %s
        """,
        (job_id,),
    )


def fail_job(cursor, job_id: int, message: str) -> None:
    cursor.execute(
        """
        UPDATE worker_jobs
        SET status = 'failed',
            stage_label = 'ERROR',
            queue_comment = %s,
            completed_at = UTC_TIMESTAMP(),
            error_message = %s,
            locked_at = NULL,
            locked_by = NULL
        WHERE id = %s
        """,
        (message[:240], message[:1000], job_id),
    )


def requeue_job(cursor, job_id: int, payload: dict, comment: str, delay_seconds: int = 0, error: str | None = None) -> None:
    run_after = (datetime.now(timezone.utc) + timedelta(seconds=max(0, delay_seconds))).strftime('%Y-%m-%d %H:%M:%S')
    cursor.execute(
        """
        UPDATE worker_jobs
        SET status = 'queued',
            stage_label = 'Queued',
            queue_comment = %s,
            payload_json = %s,
            run_after = %s,
            locked_at = NULL,
            locked_by = NULL,
            error_message = %s
        WHERE id = %s
        """,
        (
            comment[:240],
            json.dumps(payload, separators=(',', ':')),
            run_after,
            (error[:1000] if isinstance(error, str) else None),
            job_id,
        ),
    )


def is_valid_entry_uid(value: str) -> bool:
    return bool(re.match(r'^\d{14}-rjournaler-[A-Z][0-9]{6}-[a-z0-9]{6}$', value))


def fetch_entry_content(cursor, entry_uid: str) -> dict | None:
    cursor.execute(
        """
        SELECT entry_uid, entry_date, title, content_raw, weather_location_key, weather_location_json, created_at, updated_at
        FROM journal_entries
        WHERE entry_uid = %s
        LIMIT 1
        """,
        (entry_uid,),
    )
    row = cursor.fetchone()
    if row is None:
        return None

    uid, entry_date, title, content_raw, weather_location_key, weather_location_json, created_at, updated_at = row
    parsed_weather_location: dict = {}
    if isinstance(weather_location_json, dict):
        parsed_weather_location = weather_location_json
    elif isinstance(weather_location_json, str) and weather_location_json.strip() != '':
        try:
            decoded = json.loads(weather_location_json)
            if isinstance(decoded, dict):
                parsed_weather_location = decoded
        except Exception:
            parsed_weather_location = {}

    return {
        'entry_uid': uid,
        'entry_date': str(entry_date or '').strip(),
        'title': title or '',
        'content_raw': content_raw or '',
        'weather_location_key': str(weather_location_key or '').strip(),
        'weather_location': parsed_weather_location,
        'created_at': created_at,
        'updated_at': updated_at,
    }


def normalize_entry_weather_location(raw: dict | None, fallback_key: str = '') -> dict:
    source = raw if isinstance(raw, dict) else {}

    normalized = {
        'key': str(source.get('key') or fallback_key or DEFAULT_ENTRY_WEATHER_LOCATION['key']).strip(),
        'label': str(source.get('label') or '').strip(),
        'city': str(source.get('city') or '').strip(),
        'state': str(source.get('state') or '').strip(),
        'zip': str(source.get('zip') or '').strip(),
        'country': str(source.get('country') or '').strip().upper(),
    }

    if normalized['country'] == '':
        normalized['country'] = str(DEFAULT_ENTRY_WEATHER_LOCATION['country'])

    if normalized['city'] == '':
        normalized['city'] = str(DEFAULT_ENTRY_WEATHER_LOCATION['city'])
    if normalized['state'] == '':
        normalized['state'] = str(DEFAULT_ENTRY_WEATHER_LOCATION['state'])
    if normalized['zip'] == '':
        normalized['zip'] = str(DEFAULT_ENTRY_WEATHER_LOCATION['zip'])

    if normalized['label'] == '':
        normalized['label'] = f"{normalized['city']}, {normalized['state']}, {normalized['country']}"

    return normalized


def entry_weather_location(entry: dict) -> dict:
    raw = entry.get('weather_location') if isinstance(entry, dict) else {}
    fallback_key = str(entry.get('weather_location_key') or '').strip() if isinstance(entry, dict) else ''
    return normalize_entry_weather_location(raw if isinstance(raw, dict) else {}, fallback_key)


def throttle_noaa_requests(cursor) -> None:
    lock_acquired = False
    try:
        cursor.execute("SELECT GET_LOCK(%s, 30)", (NOAA_RATE_LIMIT_LOCK_NAME,))
        row = cursor.fetchone()
        lock_acquired = row is not None and int(row[0]) == 1
        if not lock_acquired:
            # Fallback to a fixed pause when the lock cannot be acquired.
            time.sleep(NOAA_RATE_LIMIT_SECONDS)
            return

        cursor.execute(
            """
            SELECT setting_value
            FROM app_settings
            WHERE setting_key = %s
            LIMIT 1
            """,
            (NOAA_RATE_LIMIT_SETTING_KEY,),
        )
        row = cursor.fetchone()
        last_touch = 0.0
        if row is not None:
            raw_value = row[0] if not isinstance(row, dict) else row.get('setting_value', '0')
            try:
                last_touch = float(str(raw_value).strip() or '0')
            except Exception:
                last_touch = 0.0

        elapsed = time.time() - last_touch
        wait_for = NOAA_RATE_LIMIT_SECONDS - elapsed
        if wait_for > 0:
            time.sleep(wait_for)

        now_touch = f"{time.time():.6f}"
        cursor.execute(
            """
            INSERT INTO app_settings (setting_key, setting_value, updated_by_user_id, updated_at)
            VALUES (%s, %s, NULL, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by_user_id = NULL,
                updated_at = UTC_TIMESTAMP()
            """,
            (NOAA_RATE_LIMIT_SETTING_KEY, now_touch),
        )
    finally:
        if lock_acquired:
            try:
                cursor.execute("SELECT RELEASE_LOCK(%s)", (NOAA_RATE_LIMIT_LOCK_NAME,))
                cursor.fetchone()
            except Exception:
                pass


def fetch_noaa_weather_for_location(location: dict, entry_date: str | None = None) -> dict:
    weather_module_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'weather'))
    if weather_module_dir not in sys.path:
        sys.path.insert(0, weather_module_dir)

    from noaa_weather import fetch_weather  # type: ignore

    payload = fetch_weather(location, entry_date=entry_date)
    if not isinstance(payload, dict):
        raise RuntimeError('NOAA weather fetch returned invalid payload')
    if payload.get('ok') is not True:
        raise RuntimeError(str(payload.get('error') or 'NOAA weather fetch failed'))
    return payload


def upsert_meta_group_3(cursor, entry_uid: str, weather_payload: dict) -> None:
    location = weather_payload.get('location') if isinstance(weather_payload.get('location'), dict) else {}
    current = weather_payload.get('current') if isinstance(weather_payload.get('current'), dict) else {}
    forecast = weather_payload.get('forecast') if isinstance(weather_payload.get('forecast'), dict) else {}
    source_provider = str(weather_payload.get('source_provider') or 'NOAA').strip() or 'NOAA'

    cursor.execute(
        """
        INSERT INTO entry_meta_group_3 (
            entry_uid,
            source_provider,
            location_label,
            location_city,
            location_state,
            location_zip,
            location_country,
            latitude,
            longitude,
            observed_at,
            current_summary,
            current_temperature_f,
            current_feels_like_f,
            current_humidity_percent,
            current_wind_speed_mph,
            forecast_name,
            forecast_short,
            map_url,
            weather_json,
            updated_at
        )
        VALUES (
            %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, UTC_TIMESTAMP()
        )
        ON DUPLICATE KEY UPDATE
            source_provider = VALUES(source_provider),
            location_label = VALUES(location_label),
            location_city = VALUES(location_city),
            location_state = VALUES(location_state),
            location_zip = VALUES(location_zip),
            location_country = VALUES(location_country),
            latitude = VALUES(latitude),
            longitude = VALUES(longitude),
            observed_at = VALUES(observed_at),
            current_summary = VALUES(current_summary),
            current_temperature_f = VALUES(current_temperature_f),
            current_feels_like_f = VALUES(current_feels_like_f),
            current_humidity_percent = VALUES(current_humidity_percent),
            current_wind_speed_mph = VALUES(current_wind_speed_mph),
            forecast_name = VALUES(forecast_name),
            forecast_short = VALUES(forecast_short),
            map_url = VALUES(map_url),
            weather_json = VALUES(weather_json),
            updated_at = VALUES(updated_at)
        """,
        (
            entry_uid,
            source_provider[:32],
            str(location.get('label') or '')[:255],
            str(location.get('city') or '')[:128],
            str(location.get('state') or '')[:64],
            str(location.get('zip') or '')[:32],
            str(location.get('country') or 'US')[:8],
            location.get('lat'),
            location.get('lon'),
            str(current.get('observed_at') or '')[:64],
            str(current.get('summary') or '')[:255],
            current.get('temperature_f'),
            current.get('feels_like_f'),
            current.get('humidity_percent'),
            current.get('wind_speed_mph'),
            str(forecast.get('name') or '')[:128],
            str(forecast.get('short') or '')[:255],
            str(weather_payload.get('map_url') or '')[:500],
            json.dumps(weather_payload, separators=(',', ':')),
        ),
    )


def upsert_meta_group_0(cursor, entry_uid: str, entry_title: str, created_datetime: str, modified_datetime: str, content_raw: str) -> None:
    entry_hash = hashlib.sha256(content_raw.encode('utf-8')).hexdigest()
    cursor.execute(
        """
        INSERT INTO entry_meta_group_0 (entry_uid, entry_title, created_datetime, modified_datetime, entry_hash_sha256, updated_at)
        VALUES (%s, %s, %s, %s, %s, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            entry_title = VALUES(entry_title),
            created_datetime = VALUES(created_datetime),
            modified_datetime = VALUES(modified_datetime),
            entry_hash_sha256 = VALUES(entry_hash_sha256),
            updated_at = VALUES(updated_at)
        """,
        (entry_uid, entry_title[:255], created_datetime, modified_datetime, entry_hash),
    )


def _safe_textstat_float(metric_name: str, content_raw: str) -> float | None:
    try:
        value = getattr(textstat, metric_name)(content_raw)
        if isinstance(value, (int, float)):
            return float(value)
    except Exception:
        return None
    return None


def build_meta_group_1_stats(content_raw: str) -> dict:
    words = [token for token in re.split(r'\s+', content_raw.strip()) if token]
    word_count_value = len(words)

    avg_word_length = (sum(len(token) for token in words) / len(words)) if words else 0.0
    long_word_count = len([token for token in words if len(token) >= 7])
    long_word_ratio = (long_word_count / len(words)) if words else 0.0

    sentence_count = 0
    try:
        sentence_count = int(textstat.sentence_count(content_raw))
    except Exception:
        sentence_count = 0

    thought_fragmentation = 0.0
    if word_count_value > 0:
        thought_fragmentation = sentence_count / max(1.0, word_count_value / 100.0)

    if word_count_value == 0:
        reading_time_minutes = 0.0
    else:
        reading_time_minutes = round((word_count_value / 200.0), 1)
        if reading_time_minutes <= 0.0:
            reading_time_minutes = 0.1

    return {
        'word_count': word_count_value,
        'reading_time_minutes': reading_time_minutes,
        'flesch_reading_ease': _safe_textstat_float('flesch_reading_ease', content_raw),
        'flesch_kincaid_grade': _safe_textstat_float('flesch_kincaid_grade', content_raw),
        'gunning_fog': _safe_textstat_float('gunning_fog', content_raw),
        'smog_index': _safe_textstat_float('smog_index', content_raw),
        'automated_readability_index': _safe_textstat_float('automated_readability_index', content_raw),
        'dale_chall': _safe_textstat_float('dale_chall_readability_score', content_raw),
        'average_word_length': round(avg_word_length, 3),
        'long_word_ratio': round(long_word_ratio, 3),
        'thought_fragmentation': round(thought_fragmentation, 3),
    }


def upsert_meta_group_1(cursor, entry_uid: str, payload: dict) -> None:
    cursor.execute(
        """
        INSERT INTO entry_meta_group_1 (
            entry_uid,
            word_count,
            reading_time_minutes,
            flesch_reading_ease,
            flesch_kincaid_grade,
            gunning_fog,
            smog_index,
            automated_readability_index,
            dale_chall,
            average_word_length,
            long_word_ratio,
            thought_fragmentation,
            updated_at
        )
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            word_count = VALUES(word_count),
            reading_time_minutes = VALUES(reading_time_minutes),
            flesch_reading_ease = VALUES(flesch_reading_ease),
            flesch_kincaid_grade = VALUES(flesch_kincaid_grade),
            gunning_fog = VALUES(gunning_fog),
            smog_index = VALUES(smog_index),
            automated_readability_index = VALUES(automated_readability_index),
            dale_chall = VALUES(dale_chall),
            average_word_length = VALUES(average_word_length),
            long_word_ratio = VALUES(long_word_ratio),
            thought_fragmentation = VALUES(thought_fragmentation),
            updated_at = VALUES(updated_at)
        """,
        (
            entry_uid,
            int(payload.get('word_count', 0)),
            float(payload.get('reading_time_minutes', 0.0)),
            payload.get('flesch_reading_ease'),
            payload.get('flesch_kincaid_grade'),
            payload.get('gunning_fog'),
            payload.get('smog_index'),
            payload.get('automated_readability_index'),
            payload.get('dale_chall'),
            payload.get('average_word_length'),
            payload.get('long_word_ratio'),
            payload.get('thought_fragmentation'),
        ),
    )


def fallback_analysis(content_raw: str, warning: str) -> dict:
    words = [token for token in re.split(r'\s+', content_raw.strip()) if token]
    preview = content_raw.strip().replace('\n', ' ')
    if len(preview) > 280:
        preview = preview[:277] + '...'

    mood = 'neutral'
    text_l = content_raw.lower()
    if any(token in text_l for token in ('happy', 'great', 'good', 'grateful', 'calm')):
        mood = 'positive'
    if any(token in text_l for token in ('sad', 'anxious', 'angry', 'tired', 'stressed', 'depressed')):
        mood = 'negative'

    return {
        'summary': preview,
        'mood': mood,
        'topics': [],
        'action_items': [],
        '_fallback': True,
        '_warning': warning,
        '_word_count': len(words),
    }


def call_ollama_analysis(cfg: RuntimeConfig, prompt: str, content_raw: str) -> dict:
    body = {
        'model': cfg.ollama_model,
        'prompt': prompt,
        'stream': False,
        'format': 'json',
    }
    request = urllib.request.Request(
        cfg.ollama_url,
        data=json.dumps(body).encode('utf-8'),
        headers={'Content-Type': 'application/json'},
        method='POST',
    )

    try:
        with urllib.request.urlopen(request, timeout=cfg.ollama_timeout_seconds) as response:
            payload = json.loads(response.read().decode('utf-8'))
    except (
        urllib.error.URLError,
        TimeoutError,
        socket.timeout,
        ConnectionError,
        ConnectionResetError,
        ConnectionAbortedError,
        http.client.HTTPException,
    ) as exc:
        if cfg.ollama_required:
            raise OllamaUnavailableError(f'ollama unavailable: {exc}') from exc
        return fallback_analysis(content_raw, f'ollama unavailable: {exc}')

    response_text = payload.get('response', '')
    if not isinstance(response_text, str) or response_text.strip() == '':
        if cfg.ollama_required:
            raise OllamaUnavailableError('ollama returned empty response')
        return fallback_analysis(content_raw, 'ollama returned empty response')

    try:
        parsed = json.loads(response_text)
    except json.JSONDecodeError as exc:
        if cfg.ollama_required:
            raise OllamaUnavailableError('ollama response is not valid JSON') from exc
        return fallback_analysis(content_raw, 'ollama response is not valid JSON')

    if not isinstance(parsed, dict):
        if cfg.ollama_required:
            raise OllamaUnavailableError('ollama response JSON must be an object')
        return fallback_analysis(content_raw, 'ollama response JSON must be an object')

    return parsed


def upsert_meta_group_2(cursor, entry_uid: str, llm_model: str, analysis_json: dict) -> None:
    cursor.execute(
        """
        INSERT INTO entry_meta_group_2 (entry_uid, llm_model, analysis_json, updated_at)
        VALUES (%s, %s, %s, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            llm_model = VALUES(llm_model),
            analysis_json = VALUES(analysis_json),
            updated_at = VALUES(updated_at)
        """,
        (entry_uid, llm_model[:128], json.dumps(analysis_json, separators=(',', ':'))),
    )


def count_words(text: str) -> int:
    return len([token for token in re.split(r'\s+', text.strip()) if token])


def estimate_readability(word_count: int) -> float:
    if word_count <= 0:
        return 0.0
    if word_count < 80:
        return 4.5
    if word_count < 200:
        return 6.8
    if word_count < 400:
        return 9.2
    return 11.5


def upsert_entry_metrics(cursor, entry_uid: str, content_raw: str) -> None:
    word_count = count_words(content_raw)
    metrics_json = {
        'source': 'autobot.metrics_finalize',
        'word_count': word_count,
        'readability_grade': estimate_readability(word_count),
        'misspelled_count': 0,
    }
    cursor.execute(
        """
        INSERT INTO entry_metrics (entry_uid, readability_grade, misspelled_count, metrics_json, generated_at)
        VALUES (%s, %s, %s, %s, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            readability_grade = VALUES(readability_grade),
            misspelled_count = VALUES(misspelled_count),
            metrics_json = VALUES(metrics_json),
            generated_at = VALUES(generated_at)
        """,
        (
            entry_uid,
            metrics_json['readability_grade'],
            0,
            json.dumps(metrics_json, separators=(',', ':')),
        ),
    )


def has_required_metadata(cursor, entry_uid: str) -> bool:
    cursor.execute(
        """
        SELECT
            EXISTS(SELECT 1 FROM entry_meta_group_0 WHERE entry_uid = %s) AS has_g0,
            EXISTS(SELECT 1 FROM entry_meta_group_1 WHERE entry_uid = %s) AS has_g1,
            EXISTS(SELECT 1 FROM entry_meta_group_2 WHERE entry_uid = %s) AS has_g2,
            EXISTS(SELECT 1 FROM entry_meta_group_3 WHERE entry_uid = %s) AS has_g3
        """,
        (entry_uid, entry_uid, entry_uid, entry_uid),
    )
    row = cursor.fetchone()
    if row is None:
        return False
    has_g0, has_g1, has_g2, has_g3 = row
    return int(has_g0) == 1 and int(has_g1) == 1 and int(has_g2) == 1 and int(has_g3) == 1


def get_entry_stage_presence(cursor, entry_uid: str) -> dict[str, bool]:
    cursor.execute(
        """
        SELECT
            EXISTS(SELECT 1 FROM entry_meta_group_0 WHERE entry_uid = %s) AS has_g0,
            EXISTS(SELECT 1 FROM entry_meta_group_1 WHERE entry_uid = %s) AS has_g1,
            EXISTS(SELECT 1 FROM entry_meta_group_2 WHERE entry_uid = %s) AS has_g2,
            EXISTS(SELECT 1 FROM entry_meta_group_3 WHERE entry_uid = %s) AS has_g3,
            EXISTS(SELECT 1 FROM entry_metrics WHERE entry_uid = %s) AS has_metrics
        """,
        (entry_uid, entry_uid, entry_uid, entry_uid, entry_uid),
    )
    row = cursor.fetchone()
    if row is None:
        return {
            'meta_group_0': False,
            'meta_group_1': False,
            'meta_group_2_llm': False,
            'meta_group_3_weather': False,
            'metrics_finalize': False,
        }

    has_g0, has_g1, has_g2, has_g3, has_metrics = row
    return {
        'meta_group_0': int(has_g0) == 1,
        'meta_group_1': int(has_g1) == 1,
        'meta_group_2_llm': int(has_g2) == 1,
        'meta_group_3_weather': int(has_g3) == 1,
        'metrics_finalize': int(has_metrics) == 1,
    }


def heal_pipeline_from_stage_presence(cursor, entry_uid: str, payload: dict, stages: list[dict]) -> dict:
    stage_presence = get_entry_stage_presence(cursor, entry_uid)
    payload = ensure_pipeline_state(payload, stages)

    completed = payload.get('pipeline', {}).get('completed', [])
    if not isinstance(completed, list):
        completed = []

    completed_set = {str(value) for value in completed}
    for stage in stages:
        stage_id = str(stage.get('id', ''))
        if stage_id == '':
            continue
        if stage_presence.get(stage_id, False):
            completed_set.add(stage_id)

    payload['pipeline']['completed'] = list(completed_set)
    return ensure_pipeline_state(payload, stages)


def read_prompt_template(cfg: RuntimeConfig, prompt_file: str | None) -> str:
    if not isinstance(prompt_file, str) or prompt_file.strip() == '':
        return ''
    path = os.path.join(cfg.prompt_dir, prompt_file)
    if not os.path.isfile(path):
        return ''
    try:
        with open(path, 'r', encoding='utf-8') as handle:
            return handle.read()
    except Exception:
        return ''


def build_prompt_from_template(template: str, entry_uid: str, content_raw: str) -> str:
    base = template if template.strip() != '' else (
        'Return ONLY JSON with keys summary, mood, topics, action_items. '
        'Use concise values. '
        'Entry UID: {entry_uid}. Text: {content}'
    )
    return base.replace('{entry_uid}', entry_uid).replace('{content}', content_raw[:6000])


def process_stage_for_job(cursor, job: dict, stage: dict, stages: list[dict], cfg: RuntimeConfig) -> dict:
    entry_uid = str(job['entry_uid']).strip()
    if not is_valid_entry_uid(entry_uid):
        raise RuntimeError('Invalid entry_uid format')

    payload = ensure_pipeline_state(job['payload'], stages)
    remaining = payload.get('pipeline', {}).get('remaining', [])
    if not isinstance(remaining, list) or len(remaining) == 0:
        return payload

    stage_id = str(stage['id'])
    if stage_id not in {str(value) for value in remaining}:
        return payload

    entry = fetch_entry_content(cursor, entry_uid)
    if entry is None:
        raise RuntimeError(f'Entry not found for UID {entry_uid}')

    content_raw = str(entry['content_raw'])
    stage_type = str(stage.get('type', 'db')).lower()

    stage_runner_map = {
        'meta_group_0': run_meta_group_0_stage,
        'meta_group_1': run_meta_group_1_stage,
        'meta_group_2_llm': run_meta_group_2_llm_stage,
        'meta_group_3_weather': run_meta_group_3_weather_stage,
        'metrics_finalize': run_metrics_finalize_stage,
    }

    helpers = {
        'cursor': cursor,
        'upsert_meta_group_0': upsert_meta_group_0,
        'build_meta_group_1_stats': build_meta_group_1_stats,
        'upsert_meta_group_1': upsert_meta_group_1,
        'read_prompt_template': read_prompt_template,
        'build_prompt_from_template': build_prompt_from_template,
        'call_ollama_analysis': call_ollama_analysis,
        'upsert_meta_group_2': upsert_meta_group_2,
        'entry_weather_location': entry_weather_location,
        'throttle_noaa_requests': throttle_noaa_requests,
        'fetch_noaa_weather_for_location': fetch_noaa_weather_for_location,
        'upsert_meta_group_3': upsert_meta_group_3,
        'upsert_entry_metrics': upsert_entry_metrics,
    }

    stage_runner = stage_runner_map.get(stage_id)
    if stage_runner is None and stage_type == 'llm':
        stage_runner = stage_runner_map.get('meta_group_2_llm')

    if stage_runner is None:
        raise RuntimeError(f'No modular stage runner available for stage {stage_id}')

    if stage_id == 'meta_group_0':
        stage_runner(entry_uid, entry, content_raw, helpers)
    elif stage_id == 'meta_group_1':
        stage_runner(entry_uid, content_raw, helpers)
    elif stage_id == 'meta_group_2_llm' or stage_type == 'llm':
        stage_runner(entry_uid, content_raw, stage, cfg, helpers)
    elif stage_id == 'meta_group_3_weather':
        stage_runner(entry_uid, entry, helpers)
    elif stage_id == 'metrics_finalize':
        stage_runner(entry_uid, content_raw, helpers)

    payload = mark_stage_completed(payload, stage_id, stages)
    return payload


def finish_or_requeue_pipeline_job(cursor, job: dict, payload: dict, stages: list[dict]) -> None:
    job_id = int(job['id'])
    entry_uid = str(job['entry_uid'])
    remaining = payload.get('pipeline', {}).get('remaining', [])
    if not isinstance(remaining, list) or len(remaining) == 0:
        if not has_required_metadata(cursor, entry_uid):
            payload = heal_pipeline_from_stage_presence(cursor, entry_uid, payload, stages)
            remaining = payload.get('pipeline', {}).get('remaining', [])
            if isinstance(remaining, list) and len(remaining) > 0:
                mark_entry_stage(cursor, entry_uid, 'IN_PROCESS')
                requeue_job(cursor, job_id, payload, f"Recovered pipeline; queued for {remaining[0]}")
                return
            raise RuntimeError('Cannot mark COMPLETE before metadata groups are present')

        cursor.execute(
            """
            SELECT workflow_stage
            FROM journal_entries
            WHERE entry_uid = %s
            LIMIT 1
            """,
            (entry_uid,),
        )
        row = cursor.fetchone()
        existing_stage = ''
        if row is not None:
            existing_stage = str(row[0] if not isinstance(row, dict) else row.get('workflow_stage', '')).strip().upper()

        target_stage = 'FINAL' if existing_stage == 'FINAL' else 'COMPLETE'
        mark_entry_stage(cursor, entry_uid, target_stage)
        complete_job(cursor, job_id)
        return

    mark_entry_stage(cursor, entry_uid, 'IN_PROCESS')
    requeue_job(cursor, job_id, payload, f"Queued for {remaining[0]}")


def backoff_seconds(attempt_count: int) -> int:
    base = 10 * (2 ** max(0, attempt_count - 1))
    return min(base, 300)


def is_deadlock_error(exc: Exception) -> bool:
    message = str(exc).lower()
    return 'deadlock found when trying to get lock' in message or '1213' in message or '40001' in message


def deadlock_retry_sleep_seconds(cfg: RuntimeConfig) -> float:
    base = max(0.5, float(cfg.poll_seconds) * 0.75)
    jitter = random.uniform(0.0, max(0.25, float(cfg.poll_seconds)))
    return min(4.0, base + jitter)


def handle_stage_error(
    cursor,
    job: dict,
    payload: dict,
    cfg: RuntimeConfig,
    exc: Exception,
    source: str,
    stage_id: str,
) -> None:
    job_id = int(job['id'])
    entry_uid = str(job['entry_uid'])
    attempts = int(job.get('attempt_count', 1))
    message = str(exc)
    message_l = message.strip().lower()
    is_ollama_unavailable = isinstance(exc, OllamaUnavailableError) or message_l.startswith('ollama unavailable:')
    is_missing_entry = message_l.startswith('entry not found for uid ')

    if is_missing_entry:
        # Entry was deleted or no longer visible; retries cannot recover this payload.
        fail_job(cursor, job_id, message)
        log_event(cfg, 'INFO', source, 'Marked job failed for missing entry', {
            'entry_uid': entry_uid,
            'stage_id': stage_id,
            'job_id': job_id,
            'attempt_count': attempts,
        })
        return

    if is_ollama_unavailable:
        if attempts < MAX_OLLAMA_UNAVAILABLE_ATTEMPTS:
            requeue_job(cursor, job_id, payload, OLLAMA_RETRY_MESSAGE, cfg.ollama_retry_seconds, message)
            log_event(cfg, 'WARN', source, 'Requeued job due to Ollama unavailability', {
                'entry_uid': entry_uid,
                'stage_id': stage_id,
                'job_id': job_id,
                'attempt_count': attempts,
                'retry_seconds': cfg.ollama_retry_seconds,
            })
            return

        mark_entry_stage(cursor, entry_uid, 'ERROR')
        fail_job(cursor, job_id, f'Ollama unavailable after {attempts} attempts: {message}')
        log_event(cfg, 'ERROR', source, 'Marked job failed after Ollama retry exhaustion', {
            'entry_uid': entry_uid,
            'stage_id': stage_id,
            'job_id': job_id,
            'attempt_count': attempts,
        })
        return

    if attempts < MAX_ATTEMPTS:
        retry_seconds = backoff_seconds(attempts)
        requeue_job(cursor, job_id, payload, f'Retry scheduled: {message}', retry_seconds, message)
        log_event(cfg, 'WARN', source, 'Requeued job after stage error', {
            'entry_uid': entry_uid,
            'stage_id': stage_id,
            'job_id': job_id,
            'attempt_count': attempts,
            'retry_seconds': retry_seconds,
        })
        return

    mark_entry_stage(cursor, entry_uid, 'ERROR')
    fail_job(cursor, job_id, message)
    log_event(cfg, 'ERROR', source, 'Marked job failed after retry exhaustion', {
        'entry_uid': entry_uid,
        'stage_id': stage_id,
        'job_id': job_id,
        'attempt_count': attempts,
    })


def count_stage_backlog(cursor, stage_id: str) -> int:
    legacy_label = legacy_stage_label_for_id(stage_id)
    cursor.execute(
        """
        SELECT COUNT(*)
        FROM worker_jobs
        WHERE job_type = 'entry_process_pipeline'
          AND status = 'queued'
          AND run_after <= UTC_TIMESTAMP()
          AND (
            JSON_SEARCH(payload_json, 'one', %s, NULL, '$.pipeline.remaining[*]') IS NOT NULL
            OR (
                %s <> ''
                AND JSON_SEARCH(payload_json, 'one', %s, NULL, '$.pipeline.remaining_labels[*]') IS NOT NULL
            )
              )
        """,
        (stage_id, legacy_label, legacy_label),
    )
    row = cursor.fetchone()
    return int(row[0]) if row is not None else 0


def autobot_heartbeat_threshold_seconds(cfg: RuntimeConfig) -> int:
    return max(8, int(cfg.poll_seconds * 3))


def autobot_processing_lock_grace_seconds(cfg: RuntimeConfig) -> int:
    # Segmented LLM stages can span multiple model calls per claimed job.
    # Keep the grace window large enough so healthy in-flight work is not
    # incorrectly self-healed as stale/orphaned.
    timeout_grace = int(max(180.0, cfg.ollama_timeout_seconds * 12.0))
    poll_grace = int(max(20.0, cfg.poll_seconds * 10.0))
    return max(timeout_grace, poll_grace)


def infer_stage_id_from_autobot(worker_name: str, notes: str) -> str:
    note_text = str(notes).strip()
    match = re.search(r'stage=([a-z0-9_\-]+)', note_text, flags=re.IGNORECASE)
    if match is not None:
        return str(match.group(1)).strip()

    name_text = str(worker_name).strip()
    name_match = re.match(r'^Autobot-(.+)-\d+$', name_text)
    if name_match is None:
        return ''
    return str(name_match.group(1)).strip()


def get_active_autobot_counts(cursor, cfg: RuntimeConfig) -> tuple[int, dict[str, int]]:
    threshold_seconds = autobot_heartbeat_threshold_seconds(cfg)
    cursor.execute(
        """
        SELECT wr.worker_name,
               COALESCE(wr.notes, '') AS notes,
               EXISTS(
                   SELECT 1
                   FROM worker_jobs wj
                   WHERE wj.status = 'processing'
                     AND wj.locked_by = wr.worker_name
               ) AS has_processing_job
        FROM worker_runs wr
        INNER JOIN (
            SELECT worker_name, MAX(id) AS max_id
            FROM worker_runs
            WHERE worker_name LIKE 'Autobot-%'
            GROUP BY worker_name
        ) latest ON latest.max_id = wr.id
        WHERE wr.worker_name LIKE 'Autobot-%'
          AND (
                                EXISTS(
                    SELECT 1
                    FROM worker_jobs wj
                    WHERE wj.status = 'processing'
                      AND wj.locked_by = wr.worker_name
                )
                                OR (wr.status = 'running' AND wr.heartbeat_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %s SECOND))
          )
        """,
        (threshold_seconds,),
    )
    rows = cursor.fetchall() or []

    stage_counts: dict[str, int] = {}
    active_total = 0
    for row in rows:
        worker_name = str(row[0] if not isinstance(row, dict) else row.get('worker_name', '')).strip()
        notes = str(row[1] if not isinstance(row, dict) else row.get('notes', '')).strip()
        if worker_name == '':
            continue

        stage_id = infer_stage_id_from_autobot(worker_name, notes)
        if stage_id == '':
            continue

        stage_counts[stage_id] = stage_counts.get(stage_id, 0) + 1
        active_total += 1

    return active_total, stage_counts


def get_active_autobot_workers_by_stage(cursor, cfg: RuntimeConfig) -> dict[str, list[str]]:
    threshold_seconds = autobot_heartbeat_threshold_seconds(cfg)
    cursor.execute(
        """
        SELECT wr.worker_name,
               COALESCE(wr.notes, '') AS notes
        FROM worker_runs wr
        INNER JOIN (
            SELECT worker_name, MAX(id) AS max_id
            FROM worker_runs
            WHERE worker_name LIKE 'Autobot-%'
            GROUP BY worker_name
        ) latest ON latest.max_id = wr.id
        WHERE wr.worker_name LIKE 'Autobot-%'
          AND (
                                EXISTS(
                    SELECT 1
                    FROM worker_jobs wj
                    WHERE wj.status = 'processing'
                      AND wj.locked_by = wr.worker_name
                )
                                OR (wr.status = 'running' AND wr.heartbeat_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %s SECOND))
          )
        """,
        (threshold_seconds,),
    )
    rows = cursor.fetchall() or []

    grouped: dict[str, list[str]] = {}
    for row in rows:
        worker_name = str(row[0] if not isinstance(row, dict) else row.get('worker_name', '')).strip()
        notes = str(row[1] if not isinstance(row, dict) else row.get('notes', '')).strip()
        if worker_name == '':
            continue
        stage_id = infer_stage_id_from_autobot(worker_name, notes)
        if stage_id == '':
            continue
        grouped.setdefault(stage_id, []).append(worker_name)

    for stage_id in list(grouped.keys()):
        grouped[stage_id].sort()
    return grouped


def get_active_workers_for_stage(cursor, cfg: RuntimeConfig, stage_id: str) -> list[str]:
    threshold_seconds = autobot_heartbeat_threshold_seconds(cfg)
    cursor.execute(
        """
        SELECT wr.worker_name,
               COALESCE(wr.notes, '') AS notes
        FROM worker_runs wr
        INNER JOIN (
            SELECT worker_name, MAX(id) AS max_id
            FROM worker_runs
            WHERE worker_name LIKE 'Autobot-%'
            GROUP BY worker_name
        ) latest ON latest.max_id = wr.id
        WHERE wr.worker_name LIKE 'Autobot-%'
          AND (
                                EXISTS(
                    SELECT 1
                    FROM worker_jobs wj
                    WHERE wj.status = 'processing'
                      AND wj.locked_by = wr.worker_name
                )
                                OR (wr.status = 'running' AND wr.heartbeat_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %s SECOND))
          )
        """,
        (threshold_seconds,),
    )
    rows = cursor.fetchall() or []

    workers: list[str] = []
    for row in rows:
        worker_name = str(row[0] if not isinstance(row, dict) else row.get('worker_name', '')).strip()
        notes = str(row[1] if not isinstance(row, dict) else row.get('notes', '')).strip()
        if worker_name == '':
            continue
        if infer_stage_id_from_autobot(worker_name, notes) != stage_id:
            continue
        workers.append(worker_name)
    workers.sort()
    return workers


def run_autobot(stage: dict, cfg_data: dict) -> None:
    cfg = RuntimeConfig(**cfg_data)
    stage_id = str(stage['id'])
    stage_label = str(stage.get('label', stage_id))
    worker_name = f"Autobot-{stage_id}-{os.getpid()}"
    source = worker_name
    idle_limit_seconds = int(max(30, cfg.autobot_idle_lifetime_minutes * 60))
    idle_started = time.time()
    drain_mode = False
    drain_logged = False

    log_event(cfg, 'INFO', source, f'Spawned for task type {stage_id}')

    while True:
        conn = None
        try:
            conn = get_connection(cfg)
            cursor = conn.cursor()
            heartbeat(cursor, worker_name, 'running', f'stage={stage_id}')
            release_stale_processing_jobs(cursor)

            cfg = apply_app_settings_overrides(runtime_from_env())
            stage_limit = int(cfg.autobot_limits.get(stage_id, cfg.autobot_max_per_type_default))
            stage_limit = max(1, stage_limit)
            active_stage_workers = get_active_workers_for_stage(cursor, cfg, stage_id)
            if worker_name in active_stage_workers:
                worker_rank = active_stage_workers.index(worker_name) + 1
                if worker_rank > stage_limit:
                    log_event(cfg, 'INFO', source, 'Exiting due to enforced stage limit', {
                        'stage_id': stage_id,
                        'worker_rank': worker_rank,
                        'stage_limit': stage_limit,
                        'active_workers': active_stage_workers,
                    })
                    break

            if is_autobot_drain_requested(cursor, worker_name):
                drain_mode = True
                if not drain_logged:
                    log_event(cfg, 'INFO', source, 'Drain requested; worker is in safe-exit mode', {'stage_id': stage_id})
                    drain_logged = True

            if drain_mode:
                break

            job = claim_pipeline_job_for_stage(cursor, worker_name, stage_id, stage_label)
            if job is None:
                if (time.time() - idle_started) >= idle_limit_seconds:
                    log_event(cfg, 'INFO', source, f'Idle timeout reached for {stage_id}; exiting')
                    break
                time.sleep(cfg.poll_seconds)
                continue

            log_event(cfg, 'INFO', source, 'Claimed stage task', {
                'entry_uid': job['entry_uid'],
                'stage_id': stage_id,
                'job_id': job['id'],
                'attempt_count': job.get('attempt_count', 0),
            })

            idle_started = time.time()
            stages = load_pipeline_stages(cfg)
            payload = ensure_pipeline_state(job['payload'], stages)
            try:
                payload = process_stage_for_job(cursor, job, stage, stages, cfg)
                finish_or_requeue_pipeline_job(cursor, job, payload, stages)
                log_event(cfg, 'INFO', source, 'Completed stage task', {
                    'entry_uid': job['entry_uid'],
                    'stage_id': stage_id,
                    'job_id': job['id'],
                })
                if is_autobot_drain_requested(cursor, worker_name):
                    drain_mode = True
                if drain_mode:
                    log_event(cfg, 'INFO', source, 'Drain mode active; task completed and exiting', {
                        'entry_uid': job['entry_uid'],
                        'stage_id': stage_id,
                        'job_id': job['id'],
                    })
                    break
            except Exception as stage_exc:
                handle_stage_error(cursor, job, payload, cfg, stage_exc, source, stage_id)
                error_text = str(stage_exc)
                if error_text.strip().lower().startswith('entry not found for uid '):
                    log_event(cfg, 'INFO', source, 'Skipping job for missing entry', {
                        'entry_uid': job['entry_uid'],
                        'stage_id': stage_id,
                        'job_id': job['id'],
                        'error': error_text,
                    })
                else:
                    log_event(cfg, 'WARN', source, 'Stage task failed', {
                        'entry_uid': job['entry_uid'],
                        'stage_id': stage_id,
                        'job_id': job['id'],
                        'error': error_text,
                    })
                if is_autobot_drain_requested(cursor, worker_name):
                    drain_mode = True
                    log_event(cfg, 'INFO', source, 'Drain mode active after task failure; exiting', {
                        'entry_uid': job['entry_uid'],
                        'stage_id': stage_id,
                        'job_id': job['id'],
                    })
                    break
        except Exception as exc:
            if is_deadlock_error(exc):
                retry_sleep = deadlock_retry_sleep_seconds(cfg)
                log_event(cfg, 'WARN', source, 'Autobot deadlock detected; retrying loop', {
                    'error': str(exc),
                    'retry_sleep_seconds': retry_sleep,
                })
                time.sleep(retry_sleep)
            else:
                log_event(cfg, 'ERROR', source, f'Autobot loop error: {exc}')
                time.sleep(cfg.poll_seconds)
        finally:
            if conn is not None and conn.is_connected():
                conn.close()

    conn = None
    try:
        conn = get_connection(cfg)
        cursor = conn.cursor()
        heartbeat(cursor, worker_name, 'stopped', f'stage={stage_id} shutdown')
    except Exception:
        pass
    finally:
        if conn is not None and conn.is_connected():
            conn.close()

    clear_autobot_drain_request(cfg, worker_name)


def run_optimus() -> None:
    cfg = apply_app_settings_overrides(runtime_from_env())
    optimus_name = 'Optimus'
    processes: dict[str, list[Process]] = {}
    last_stage_refresh = 0.0
    stages: list[dict] = []

    lock_conn = acquire_optimus_lock(cfg)
    if lock_conn is None:
        log_event(cfg, 'WARN', optimus_name, 'Another Optimus instance is already active; exiting')
        return

    log_event(cfg, 'INFO', optimus_name, 'Optimus orchestrator booting')

    while True:
        conn = None
        try:
            now_ts = time.time()
            cfg = apply_app_settings_overrides(runtime_from_env())
            if now_ts - last_stage_refresh > 5:
                stages = load_pipeline_stages(cfg)
                last_stage_refresh = now_ts

            conn = get_connection(cfg)
            cursor = conn.cursor()
            heartbeat(cursor, optimus_name, 'running', f'poll={cfg.poll_seconds}s')

            release_stale_processing_jobs(cursor)
            self_heal_counts = self_heal_autobot_runtime_state(cursor, cfg)
            if sum(self_heal_counts.values()) > 0:
                log_event(cfg, 'WARN', optimus_name, 'Applied autobot self-heal actions', self_heal_counts)
            deleted_completed_jobs = clean_old_completed_jobs(cursor, cfg.queue_complete_retention_hours)
            deleted_failed_jobs = clean_old_failed_jobs(cursor, cfg.queue_failed_retention_hours)
            marked_old_orchestrator_logs = clean_old_orchestrator_logs(cursor, cfg.orchestrator_log_retention_hours)
            deleted_audit_logs = clean_old_audit_logs(cursor, cfg.audit_log_retention_days)
            if deleted_completed_jobs > 0 or deleted_failed_jobs > 0 or marked_old_orchestrator_logs > 0 or deleted_audit_logs > 0:
                log_event(cfg, 'INFO', optimus_name, 'Applied retention cleanup', {
                    'deleted_completed_jobs': deleted_completed_jobs,
                    'deleted_failed_jobs': deleted_failed_jobs,
                    'marked_old_orchestrator_logs': marked_old_orchestrator_logs,
                    'deleted_audit_logs': deleted_audit_logs,
                    'queue_complete_retention_hours': cfg.queue_complete_retention_hours,
                    'queue_failed_retention_hours': cfg.queue_failed_retention_hours,
                    'orchestrator_log_retention_hours': cfg.orchestrator_log_retention_hours,
                    'audit_log_retention_days': cfg.audit_log_retention_days,
                })
            queue_auto_finish_entries(cursor, cfg)

            # Reap dead children.
            for stage_id in list(processes.keys()):
                alive = []
                for proc in processes[stage_id]:
                    if proc.is_alive():
                        alive.append(proc)
                    else:
                        proc.join(timeout=0.1)
                processes[stage_id] = alive

            active_total, stage_counts = get_active_autobot_counts(cursor, cfg)
            slots_remaining = max(0, cfg.optimus_max_autobots - active_total)

            # Hard-enforce per-stage limits by draining any surplus workers.
            active_by_stage = get_active_autobot_workers_by_stage(cursor, cfg)
            applied_surplus_drain = False
            for stage in stages:
                stage_id = str(stage.get('id', ''))
                if stage_id == '':
                    continue
                stage_limit = int(cfg.autobot_limits.get(stage_id, cfg.autobot_max_per_type_default))
                stage_limit = max(1, stage_limit)
                stage_workers = list(active_by_stage.get(stage_id, []))
                if len(stage_workers) <= stage_limit:
                    continue

                surplus = stage_workers[stage_limit:]
                for worker_name in surplus:
                    request_autobot_drain(cfg, worker_name)
                    applied_surplus_drain = True

                log_event(cfg, 'WARN', optimus_name, 'Draining surplus stage workers to enforce limit', {
                    'stage_id': stage_id,
                    'stage_limit': stage_limit,
                    'active_workers': stage_workers,
                    'draining_workers': surplus,
                })

            # Re-evaluate active counts after issuing drain requests so spawn decisions
            # are based on current state rather than pre-drain snapshots.
            if applied_surplus_drain:
                active_total, stage_counts = get_active_autobot_counts(cursor, cfg)
                slots_remaining = max(0, cfg.optimus_max_autobots - active_total)

            for stage in stages:
                if slots_remaining <= 0:
                    break
                stage_id = str(stage['id'])
                stage_limit = int(cfg.autobot_limits.get(stage_id, cfg.autobot_max_per_type_default))
                stage_limit = max(1, stage_limit)

                stage_backlog = count_stage_backlog(cursor, stage_id)
                running_for_stage = int(stage_counts.get(stage_id, 0))
                if stage_backlog <= 0 or running_for_stage >= stage_limit:
                    continue

                can_spawn = min(stage_limit - running_for_stage, slots_remaining, stage_backlog)
                for _ in range(can_spawn):
                    proc = Process(target=run_autobot, args=(stage, cfg.__dict__), daemon=False)
                    proc.start()
                    processes.setdefault(stage_id, []).append(proc)
                    slots_remaining -= 1
                    running_for_stage += 1
                    stage_counts[stage_id] = running_for_stage
                    log_event(cfg, 'INFO', optimus_name, 'Spawned Autobot', {
                        'stage_id': stage_id,
                        'pid': proc.pid,
                        'stage_backlog': stage_backlog,
                        'running_for_stage': running_for_stage,
                        'stage_limit': stage_limit,
                        'active_total': active_total,
                        'global_limit': cfg.optimus_max_autobots,
                    })

            time.sleep(cfg.poll_seconds)
        except KeyboardInterrupt:
            log_event(cfg, 'WARN', optimus_name, 'KeyboardInterrupt received; shutting down')
            break
        except Exception as exc:
            if is_deadlock_error(exc):
                retry_sleep = deadlock_retry_sleep_seconds(cfg)
                log_event(cfg, 'WARN', optimus_name, 'Orchestrator deadlock detected; retrying loop', {
                    'error': str(exc),
                    'retry_sleep_seconds': retry_sleep,
                })
                time.sleep(retry_sleep)
            else:
                log_event(cfg, 'ERROR', optimus_name, f'Orchestrator loop error: {exc}')
                time.sleep(cfg.poll_seconds)
        finally:
            if conn is not None and conn.is_connected():
                conn.close()

    # Graceful child shutdown.
    for stage_id, stage_procs in processes.items():
        for proc in stage_procs:
            if proc.is_alive():
                proc.join(timeout=2)
                if proc.is_alive():
                    proc.terminate()
                    proc.join(timeout=1)
        if len(stage_procs) > 0:
            log_event(cfg, 'INFO', optimus_name, 'Autobot stage pool stopped', {'stage_id': stage_id})

    conn = None
    try:
        conn = get_connection(cfg)
        cursor = conn.cursor()
        heartbeat(cursor, optimus_name, 'stopped', 'orchestrator shutdown')
    except Exception:
        pass
    finally:
        if conn is not None and conn.is_connected():
            conn.close()

    release_optimus_lock(lock_conn)


if __name__ == '__main__':
    run_optimus()
