from __future__ import annotations

from typing import Any


def run_stage(entry_uid: str, entry: dict[str, Any], content_raw: str, helpers: dict[str, Any]) -> None:
    upsert_meta_group_0 = helpers["upsert_meta_group_0"]
    upsert_meta_group_0(
        helpers["cursor"],
        entry_uid,
        str(entry.get("title", "Untitled Entry")),
        str(entry.get("created_at")),
        str(entry.get("updated_at")),
        content_raw,
    )
