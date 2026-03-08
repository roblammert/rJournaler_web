from __future__ import annotations

from typing import Any


def run_stage(entry_uid: str, content_raw: str, helpers: dict[str, Any]) -> None:
    upsert_entry_metrics = helpers["upsert_entry_metrics"]
    upsert_entry_metrics(helpers["cursor"], entry_uid, content_raw)
