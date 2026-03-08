from __future__ import annotations

from typing import Any


def run_stage(entry_uid: str, content_raw: str, helpers: dict[str, Any]) -> None:
    build_meta_group_1_stats = helpers["build_meta_group_1_stats"]
    upsert_meta_group_1 = helpers["upsert_meta_group_1"]
    payload = build_meta_group_1_stats(content_raw)
    upsert_meta_group_1(helpers["cursor"], entry_uid, payload)
