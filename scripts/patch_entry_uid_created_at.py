#!/usr/bin/env python3
"""One-time patch to rebuild entry_uid timestamp prefixes from entry created_at.

Behavior:
- Recomputes the first UID section as YYYYMMDDHHMMSS in America/Chicago
  using journal_entries.created_at (stored in UTC).
- Keeps the remainder of the UID unchanged.
- Propagates updates to every table column named `entry_uid`.
- Also updates worker_jobs.payload_json at $.entry_uid when present.

Usage:
- Dry run (default):
    python scripts/patch_entry_uid_created_at.py
- Apply changes:
    python scripts/patch_entry_uid_created_at.py --apply
"""

from __future__ import annotations

import argparse
import os
import re
import sys
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from pathlib import Path

import mysql.connector

UID_PATTERN = re.compile(r"^(?P<ts>\d{14})-(?P<rest>.+)$")


def nth_weekday_of_month(year: int, month: int, weekday: int, n: int) -> datetime:
    """Return date of nth weekday in month as naive datetime at midnight.

    weekday: Monday=0 ... Sunday=6
    """
    first = datetime(year, month, 1)
    delta = (weekday - first.weekday()) % 7
    day = 1 + delta + (n - 1) * 7
    return datetime(year, month, day)


def chicago_offset_hours_for_utc(utc_dt: datetime) -> int:
    """Approximate America/Chicago offset using US DST rules (2007+).

    DST starts: second Sunday in March at 02:00 local (CST, UTC-6) => 08:00 UTC
    DST ends: first Sunday in November at 02:00 local (CDT, UTC-5) => 07:00 UTC
    """
    year = utc_dt.year

    dst_start_local_date = nth_weekday_of_month(year, 3, 6, 2)
    dst_end_local_date = nth_weekday_of_month(year, 11, 6, 1)

    dst_start_utc = datetime(year, 3, dst_start_local_date.day, 8, 0, 0, tzinfo=timezone.utc)
    dst_end_utc = datetime(year, 11, dst_end_local_date.day, 7, 0, 0, tzinfo=timezone.utc)

    if dst_start_utc <= utc_dt < dst_end_utc:
        return -5
    return -6


def utc_to_chicago(utc_dt: datetime) -> datetime:
    offset_hours = chicago_offset_hours_for_utc(utc_dt)
    return utc_dt + timedelta(hours=offset_hours)


@dataclass
class DbConfig:
    host: str
    port: int
    name: str
    user: str
    password: str


def load_env_file() -> None:
    env_path = Path(__file__).resolve().parents[1] / ".env"
    if not env_path.is_file():
        return

    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip())


def load_config() -> DbConfig:
    load_env_file()
    return DbConfig(
        host=os.getenv("DB_HOST", "127.0.0.1"),
        port=int(os.getenv("DB_PORT", "3306")),
        name=os.getenv("DB_NAME", "rjournaler_web"),
        user=os.getenv("DB_USER", "root"),
        password=os.getenv("DB_PASSWORD", ""),
    )


def utc_dt_from_db(value: object) -> datetime:
    if isinstance(value, datetime):
        dt = value
    elif isinstance(value, str):
        normalized = value.replace(" ", "T")
        dt = datetime.fromisoformat(normalized)
    else:
        raise ValueError(f"Unsupported datetime value type: {type(value)!r}")

    if dt.tzinfo is None:
        return dt.replace(tzinfo=timezone.utc)
    return dt.astimezone(timezone.utc)


def new_uid_from_created_at(old_uid: str, created_at_utc: object) -> str:
    match = UID_PATTERN.match(old_uid)
    if not match:
        raise ValueError(f"Invalid UID format: {old_uid}")

    rest = match.group("rest")
    utc_dt = utc_dt_from_db(created_at_utc)
    chicago_dt = utc_to_chicago(utc_dt)
    prefix = chicago_dt.strftime("%Y%m%d%H%M%S")
    return f"{prefix}-{rest}"


def collect_mapping(cursor) -> list[tuple[str, str]]:
    cursor.execute("SELECT entry_uid, created_at FROM journal_entries")
    rows = cursor.fetchall()

    mapping: list[tuple[str, str]] = []
    seen_new: dict[str, str] = {}

    for old_uid, created_at in rows:
        old_uid_str = str(old_uid)
        new_uid = new_uid_from_created_at(old_uid_str, created_at)
        if new_uid == old_uid_str:
            continue

        previous_old = seen_new.get(new_uid)
        if previous_old is not None and previous_old != old_uid_str:
            raise RuntimeError(
                "UID collision detected while remapping. "
                f"Both '{previous_old}' and '{old_uid_str}' map to '{new_uid}'."
            )

        seen_new[new_uid] = old_uid_str
        mapping.append((old_uid_str, new_uid))

    return mapping


def fetch_entry_uid_tables(cursor) -> list[str]:
    cursor.execute(
        """
        SELECT TABLE_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND COLUMN_NAME = 'entry_uid'
        ORDER BY TABLE_NAME ASC
        """
    )
    return [str(row[0]) for row in cursor.fetchall()]


def apply_mapping(conn, cursor, mapping: list[tuple[str, str]]) -> dict[str, int]:
    stats: dict[str, int] = {}

    cursor.execute(
        """
        CREATE TEMPORARY TABLE tmp_uid_map (
            old_uid VARCHAR(64) NOT NULL PRIMARY KEY,
            new_uid VARCHAR(64) NOT NULL UNIQUE
        ) ENGINE=MEMORY
        """
    )
    cursor.executemany(
        "INSERT INTO tmp_uid_map (old_uid, new_uid) VALUES (%s, %s)",
        mapping,
    )

    table_names = fetch_entry_uid_tables(cursor)

    for table_name in table_names:
        sql = (
            f"UPDATE `{table_name}` t "
            "INNER JOIN tmp_uid_map m ON t.entry_uid = m.old_uid "
            "SET t.entry_uid = m.new_uid"
        )
        cursor.execute(sql)
        stats[f"table:{table_name}"] = cursor.rowcount

    # Keep queued payload UID references in sync.
    cursor.execute(
        """
        UPDATE worker_jobs w
        INNER JOIN tmp_uid_map m
            ON JSON_UNQUOTE(JSON_EXTRACT(w.payload_json, '$.entry_uid')) = m.old_uid
        SET w.payload_json = JSON_SET(w.payload_json, '$.entry_uid', m.new_uid)
        """
    )
    stats["worker_jobs.payload_json"] = cursor.rowcount

    return stats


def main() -> int:
    parser = argparse.ArgumentParser(description="Patch entry_uid timestamp prefixes using created_at in America/Chicago.")
    parser.add_argument("--apply", action="store_true", help="Apply changes. Without this flag, run as dry-run.")
    args = parser.parse_args()

    cfg = load_config()

    conn = mysql.connector.connect(
        host=cfg.host,
        port=cfg.port,
        user=cfg.user,
        password=cfg.password,
        database=cfg.name,
        autocommit=True,
    )

    try:
        cursor = conn.cursor()
        mapping = collect_mapping(cursor)

        print(f"journal_entries requiring UID changes: {len(mapping)}")
        if mapping:
            sample_count = min(5, len(mapping))
            print("sample mapping:")
            for old_uid, new_uid in mapping[:sample_count]:
                print(f"  {old_uid} -> {new_uid}")

        if not args.apply:
            print("dry-run only; no changes applied. Re-run with --apply to execute.")
            return 0

        if not mapping:
            print("no changes required.")
            return 0

        conn.start_transaction()
        cursor.execute("SET FOREIGN_KEY_CHECKS = 0")
        try:
            stats = apply_mapping(conn, cursor, mapping)
            cursor.execute("SET FOREIGN_KEY_CHECKS = 1")
            conn.commit()
        except Exception:
            conn.rollback()
            cursor.execute("SET FOREIGN_KEY_CHECKS = 1")
            raise

        print("applied successfully.")
        for key in sorted(stats.keys()):
            print(f"  {key}: {stats[key]}")

        return 0
    except Exception as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 1
    finally:
        conn.close()


if __name__ == "__main__":
    raise SystemExit(main())
