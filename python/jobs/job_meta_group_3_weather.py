from __future__ import annotations

from typing import Any


def run_stage(entry_uid: str, entry: dict[str, Any], helpers: dict[str, Any]) -> None:
    entry_weather_location = helpers["entry_weather_location"]
    throttle_noaa_requests = helpers["throttle_noaa_requests"]
    fetch_noaa_weather_for_location = helpers["fetch_noaa_weather_for_location"]
    upsert_meta_group_3 = helpers["upsert_meta_group_3"]

    location = entry_weather_location(entry)
    entry_date = str(entry.get("entry_date") or "").strip()
    throttle_noaa_requests(helpers["cursor"])
    weather_payload = fetch_noaa_weather_for_location(location, entry_date=entry_date)
    upsert_meta_group_3(helpers["cursor"], entry_uid, weather_payload)
