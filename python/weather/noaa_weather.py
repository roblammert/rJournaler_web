#!/usr/bin/env python3
"""Fetch current weather summary using noaa_sdk for a session-selected location."""

from __future__ import annotations

import json
import base64
import os
import re
import sys
import time
import urllib.parse
import urllib.request
from datetime import date, datetime, time as dt_time, timedelta, timezone
from pathlib import Path
from typing import Any

from noaa_sdk import NOAA


NOAA_THROTTLE_SECONDS = 5.0


def c_to_f(value_c: float | None) -> float | None:
    if value_c is None:
        return None
    return (value_c * 9.0 / 5.0) + 32.0


def round_or_none(value: float | None, digits: int = 1) -> float | None:
    if value is None:
        return None
    return round(value, digits)


def weather_code_description(code: Any) -> str:
    try:
        numeric = int(code)
    except Exception:
        return "n/a"

    mapping = {
        0: "Clear sky",
        1: "Mainly clear",
        2: "Partly cloudy",
        3: "Overcast",
        45: "Fog",
        48: "Depositing rime fog",
        51: "Light drizzle",
        53: "Moderate drizzle",
        55: "Dense drizzle",
        56: "Light freezing drizzle",
        57: "Dense freezing drizzle",
        61: "Slight rain",
        63: "Moderate rain",
        65: "Heavy rain",
        66: "Light freezing rain",
        67: "Heavy freezing rain",
        71: "Slight snow",
        73: "Moderate snow",
        75: "Heavy snow",
        77: "Snow grains",
        80: "Slight rain showers",
        81: "Moderate rain showers",
        82: "Violent rain showers",
        85: "Slight snow showers",
        86: "Heavy snow showers",
        95: "Thunderstorm",
        96: "Thunderstorm with slight hail",
        99: "Thunderstorm with heavy hail",
    }
    return mapping.get(numeric, f"Weather code {numeric}")


def parse_metric_value(metric: Any) -> float | None:
    if not isinstance(metric, dict):
        return None
    raw = metric.get("value")
    if raw is None:
        return None
    try:
        return float(raw)
    except (TypeError, ValueError):
        return None


def speed_to_mph(metric: Any) -> float | None:
    if not isinstance(metric, dict):
        return None
    value = parse_metric_value(metric)
    if value is None:
        return None

    unit_code = str(metric.get("unitCode") or "").lower()
    if "km_h-1" in unit_code or "km/h" in unit_code:
        return value * 0.621371
    if "m_s-1" in unit_code or "m/s" in unit_code:
        return value * 2.23694
    return value


def geocode_location(location: dict[str, Any]) -> dict[str, Any]:
    city = str(location.get("city") or "").strip()
    state = str(location.get("state") or "").strip()
    postal_code = str(location.get("zip") or "").strip()
    country = str(location.get("country") or "US").strip() or "US"

    query_parts: list[str] = []
    if city:
        query_parts.append(city)
    if state:
        query_parts.append(state)
    if postal_code:
        query_parts.append(postal_code)
    if country:
        query_parts.append(country)

    if not query_parts:
        raise ValueError("Location must include city/state, or zip/country")

    query = ", ".join(query_parts)
    params = urllib.parse.urlencode({"q": query, "format": "json", "limit": 1})
    url = f"https://nominatim.openstreetmap.org/search?{params}"

    request = urllib.request.Request(
        url,
        headers={
            "User-Agent": "rJournalerWeb/1.0 (weather lookup)",
            "Accept": "application/json",
        },
    )

    with urllib.request.urlopen(request, timeout=8) as response:
        payload = response.read().decode("utf-8", errors="replace")

    rows = json.loads(payload)
    if not isinstance(rows, list) or len(rows) == 0:
        raise ValueError("Unable to resolve location")

    row = rows[0]
    lat = float(row.get("lat"))
    lon = float(row.get("lon"))
    display_name = str(row.get("display_name") or query)

    return {
        "lat": lat,
        "lon": lon,
        "display_name": display_name,
    }


def fetch_json(url: str) -> Any:
    request = urllib.request.Request(
        url,
        headers={
            "User-Agent": "rJournalerWeb/1.0 (weather lookup)",
            "Accept": "application/geo+json, application/json",
        },
    )
    with urllib.request.urlopen(request, timeout=10) as response:
        payload = response.read().decode("utf-8", errors="replace")
    return json.loads(payload)


def parse_entry_date(entry_date: str | None) -> date | None:
    value = str(entry_date or "").strip()
    if value == "":
        return None
    try:
        return datetime.strptime(value, "%Y-%m-%d").date()
    except ValueError:
        return None


def parse_observation_timestamp(value: Any) -> datetime | None:
    raw = str(value or "").strip()
    if raw == "":
        return None
    try:
        parsed = datetime.fromisoformat(raw.replace("Z", "+00:00"))
    except ValueError:
        return None
    if parsed.tzinfo is None:
        return parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc)


def pick_best_observation(features: list[Any], target_dt_utc: datetime) -> dict[str, Any] | None:
    best_payload: dict[str, Any] | None = None
    best_distance: float | None = None

    for feature in features:
        if not isinstance(feature, dict):
            continue
        props = feature.get("properties")
        if not isinstance(props, dict):
            continue
        ts = parse_observation_timestamp(props.get("timestamp"))
        if ts is None:
            continue
        distance = abs((ts - target_dt_utc).total_seconds())
        if best_distance is None or distance < best_distance:
            best_distance = distance
            best_payload = props

    return best_payload


def find_historical_observation(lat: float, lon: float, target_date: date) -> dict[str, Any] | None:
    points_url = f"https://api.weather.gov/points/{lat:.4f},{lon:.4f}"
    points_payload = fetch_json(points_url)
    if not isinstance(points_payload, dict):
        return None

    points_props = points_payload.get("properties")
    if not isinstance(points_props, dict):
        return None

    stations_url = str(points_props.get("observationStations") or "").strip()
    if stations_url == "":
        return None

    stations_payload = fetch_json(stations_url)
    if not isinstance(stations_payload, dict):
        return None

    station_features = stations_payload.get("features")
    if not isinstance(station_features, list) or len(station_features) == 0:
        return None

    noon_target_utc = datetime.combine(target_date, dt_time(hour=12), tzinfo=timezone.utc)

    # Query a 3-day window centered on target_date to improve odds of finding a nearby observation.
    start_utc = datetime.combine(target_date - timedelta(days=1), dt_time.min, tzinfo=timezone.utc)
    end_utc = datetime.combine(target_date + timedelta(days=2), dt_time.min, tzinfo=timezone.utc)
    start_s = start_utc.isoformat().replace("+00:00", "Z")
    end_s = end_utc.isoformat().replace("+00:00", "Z")

    best_payload: dict[str, Any] | None = None
    best_distance: float | None = None

    for feature in station_features[:4]:
        if not isinstance(feature, dict):
            continue
        station_id = str(feature.get("id") or "").strip()
        if station_id == "":
            continue

        obs_url = f"{station_id}/observations?start={urllib.parse.quote(start_s)}&end={urllib.parse.quote(end_s)}&limit=500"
        try:
            obs_payload = fetch_json(obs_url)
        except Exception:
            continue
        if not isinstance(obs_payload, dict):
            continue
        obs_features = obs_payload.get("features")
        if not isinstance(obs_features, list) or len(obs_features) == 0:
            continue

        candidate = pick_best_observation(obs_features, noon_target_utc)
        if not isinstance(candidate, dict):
            continue
        ts = parse_observation_timestamp(candidate.get("timestamp"))
        if ts is None:
            continue
        distance = abs((ts - noon_target_utc).total_seconds())

        if best_distance is None or distance < best_distance:
            best_distance = distance
            best_payload = candidate

    return best_payload


def fetch_open_meteo_archive(lat: float, lon: float, target_date: date) -> dict[str, Any] | None:
    date_str = target_date.strftime("%Y-%m-%d")
    params = urllib.parse.urlencode(
        {
            "latitude": f"{lat:.4f}",
            "longitude": f"{lon:.4f}",
            "start_date": date_str,
            "end_date": date_str,
            "hourly": "temperature_2m,apparent_temperature,relative_humidity_2m,wind_speed_10m,weather_code",
            "temperature_unit": "fahrenheit",
            "wind_speed_unit": "mph",
            "timezone": "auto",
        }
    )
    url = f"https://archive-api.open-meteo.com/v1/archive?{params}"

    payload = fetch_json(url)
    if not isinstance(payload, dict):
        return None

    hourly = payload.get("hourly")
    if not isinstance(hourly, dict):
        return None

    times = hourly.get("time")
    temperatures = hourly.get("temperature_2m")
    feels_like = hourly.get("apparent_temperature")
    humidities = hourly.get("relative_humidity_2m")
    wind_speeds = hourly.get("wind_speed_10m")
    weather_codes = hourly.get("weather_code")

    if not isinstance(times, list) or len(times) == 0:
        return None

    index = 0
    for idx, t_value in enumerate(times):
        stamp = str(t_value or "")
        if "T12:00" in stamp:
            index = idx
            break

    def at(values: Any, idx: int) -> Any:
        if isinstance(values, list) and 0 <= idx < len(values):
            return values[idx]
        return None

    observed_local = str(at(times, index) or "")
    if observed_local != "":
        observed_local = observed_local + ":00"

    code_value = at(weather_codes, index)
    summary = weather_code_description(code_value)

    return {
        "ok": True,
        "source_provider": "OPEN_METEO_ARCHIVE",
        "current": {
            "temperature_f": round_or_none(float(at(temperatures, index))) if at(temperatures, index) is not None else None,
            "feels_like_f": round_or_none(float(at(feels_like, index))) if at(feels_like, index) is not None else None,
            "dew_point_f": None,
            "humidity_percent": round_or_none(float(at(humidities, index))) if at(humidities, index) is not None else None,
            "wind_speed_mph": round_or_none(float(at(wind_speeds, index))) if at(wind_speeds, index) is not None else None,
            "wind_gust_mph": None,
            "wind_direction_degrees": None,
            "summary": summary,
            "observed_at": observed_local,
        },
        "forecast": {
            "name": "",
            "short": "",
            "detailed": "",
        },
    }


def get_first_period(forecast: Any) -> dict[str, Any] | None:
    if isinstance(forecast, dict):
        props = forecast.get("properties")
        if isinstance(props, dict):
            periods = props.get("periods")
            if isinstance(periods, list) and periods:
                first = periods[0]
                if isinstance(first, dict):
                    return first

    if hasattr(forecast, "__iter__") and not isinstance(forecast, (str, bytes, dict)):
        for item in forecast:
            if isinstance(item, dict):
                return item
    return None


def get_forecast_periods(forecast: Any) -> list[dict[str, Any]]:
    if isinstance(forecast, dict):
        props = forecast.get("properties")
        if isinstance(props, dict):
            periods = props.get("periods")
            if isinstance(periods, list):
                return [row for row in periods if isinstance(row, dict)]
        return []

    if hasattr(forecast, "__iter__") and not isinstance(forecast, (str, bytes, dict)):
        return [row for row in forecast if isinstance(row, dict)]

    return []


def parse_period_datetime(value: Any) -> datetime | None:
    raw = str(value or "").strip()
    if raw == "":
        return None
    try:
        parsed = datetime.fromisoformat(raw.replace("Z", "+00:00"))
    except ValueError:
        return None
    if parsed.tzinfo is None:
        return parsed.replace(tzinfo=timezone.utc)
    return parsed


def summary_word(short_forecast: Any) -> str:
    text = str(short_forecast or "").strip()
    if text == "":
        return "n/a"
    match = re.search(r"[A-Za-z]+", text)
    if not match:
        return "n/a"
    return match.group(0).title()


def build_five_day_forecast(periods: list[dict[str, Any]], max_days: int = 5) -> list[dict[str, Any]]:
    if not periods:
        return []

    by_day: dict[str, dict[str, Any]] = {}

    for period in periods:
        stamp = parse_period_datetime(period.get("startTime"))
        if stamp is None:
            continue

        day_key = stamp.date().isoformat()
        row = by_day.get(day_key)
        if row is None:
            row = {
                "date": day_key,
                "day_label": stamp.strftime("%a"),
                "icon_url": "",
                "summary_word": "n/a",
                "high_f": None,
                "low_f": None,
            }
            by_day[day_key] = row

        icon = str(period.get("icon") or "").strip()
        if icon != "" and str(row.get("icon_url") or "") == "":
            row["icon_url"] = icon

        word = summary_word(period.get("shortForecast"))
        if word != "n/a" and str(row.get("summary_word") or "n/a") == "n/a":
            row["summary_word"] = word

        temp_value = period.get("temperature")
        temp_f: float | None = None
        try:
            if temp_value is not None and str(temp_value).strip() != "":
                temp_f = float(temp_value)
        except (TypeError, ValueError):
            temp_f = None

        if temp_f is None:
            continue

        is_daytime = bool(period.get("isDaytime"))
        if is_daytime:
            current_high = row.get("high_f")
            if not isinstance(current_high, (int, float)) or temp_f > float(current_high):
                row["high_f"] = temp_f
        else:
            current_low = row.get("low_f")
            if not isinstance(current_low, (int, float)) or temp_f < float(current_low):
                row["low_f"] = temp_f

    ordered = sorted(by_day.values(), key=lambda row: str(row.get("date") or ""))
    normalized: list[dict[str, Any]] = []
    for row in ordered:
        high = row.get("high_f")
        low = row.get("low_f")

        if not isinstance(high, (int, float)) and isinstance(low, (int, float)):
            high = low
        if not isinstance(low, (int, float)) and isinstance(high, (int, float)):
            low = high

        normalized.append(
            {
                "date": str(row.get("date") or ""),
                "day_label": str(row.get("day_label") or ""),
                "icon_url": str(row.get("icon_url") or ""),
                "summary_word": str(row.get("summary_word") or "n/a"),
                "high_f": round_or_none(float(high), 0) if isinstance(high, (int, float)) else None,
                "low_f": round_or_none(float(low), 0) if isinstance(low, (int, float)) else None,
            }
        )

    return normalized[:max_days]


def build_map_url(lat: float, lon: float) -> str:
    return f"https://radar.weather.gov/?settings=v1_{lat:.4f},{lon:.4f}_8.00_10000_1_-1"


def enforce_noaa_throttle() -> None:
    project_root = Path(__file__).resolve().parents[2]
    cache_dir = project_root / "storage" / "cache" / "weather"
    cache_dir.mkdir(parents=True, exist_ok=True)

    lock_path = cache_dir / "noaa_rate_limit.lock"
    state_path = cache_dir / "noaa_rate_limit.json"
    lock_timeout = time.time() + 20.0

    while True:
        try:
            fd = os.open(str(lock_path), os.O_CREAT | os.O_EXCL | os.O_WRONLY)
            os.close(fd)
            break
        except FileExistsError:
            if time.time() > lock_timeout:
                time.sleep(NOAA_THROTTLE_SECONDS)
                return
            time.sleep(0.05)

    try:
        last_touch = 0.0
        if state_path.is_file():
            try:
                payload = json.loads(state_path.read_text(encoding="utf-8"))
                if isinstance(payload, dict):
                    last_touch = float(payload.get("last_touch", 0.0) or 0.0)
            except Exception:
                last_touch = 0.0

        elapsed = time.time() - last_touch
        wait_for = NOAA_THROTTLE_SECONDS - elapsed
        if wait_for > 0:
            time.sleep(wait_for)

        state_path.write_text(json.dumps({"last_touch": time.time()}), encoding="utf-8")
    finally:
        try:
            lock_path.unlink(missing_ok=True)
        except Exception:
            pass


def fetch_weather(location: dict[str, Any], entry_date: str | None = None) -> dict[str, Any]:
    resolved = geocode_location(location)
    lat = float(resolved["lat"])
    lon = float(resolved["lon"])

    enforce_noaa_throttle()
    noaa = NOAA()

    target_date = parse_entry_date(entry_date)
    observation = None
    if target_date is not None:
        observation = find_historical_observation(lat, lon, target_date)

    archive_payload: dict[str, Any] | None = None
    if not isinstance(observation, dict) and target_date is not None:
        archive_payload = fetch_open_meteo_archive(lat, lon, target_date)

    if isinstance(archive_payload, dict) and archive_payload.get("ok") is True:
        current = archive_payload.get("current") if isinstance(archive_payload.get("current"), dict) else {}
        forecast = archive_payload.get("forecast") if isinstance(archive_payload.get("forecast"), dict) else {}
        return {
            "ok": True,
            "source_provider": str(archive_payload.get("source_provider") or "OPEN_METEO_ARCHIVE"),
            "location": {
                "label": str(location.get("label") or resolved["display_name"]),
                "display_name": resolved["display_name"],
                "city": str(location.get("city") or ""),
                "state": str(location.get("state") or ""),
                "zip": str(location.get("zip") or ""),
                "country": str(location.get("country") or "US"),
                "lat": round(lat, 4),
                "lon": round(lon, 4),
            },
            "current": current,
            "forecast": forecast,
            "forecast_days": [],
            "map_url": build_map_url(lat, lon),
            "generated_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        }

    if not isinstance(observation, dict):
        observation_iter = noaa.get_observations_by_lat_lon(lat, lon, num_of_stations=1)
        observation = next(observation_iter, None)

    if not isinstance(observation, dict):
        raise RuntimeError("No NOAA observation available for location")

    first_period: dict[str, Any] | None = None
    forecast_days: list[dict[str, Any]] = []
    today_utc = datetime.now(timezone.utc).date()
    if target_date is None or target_date == today_utc:
        forecast = noaa.points_forecast(lat, lon)
        forecast_days = build_five_day_forecast(get_forecast_periods(forecast), max_days=5)
        first_period = get_first_period(forecast)

    temp_f = c_to_f(parse_metric_value(observation.get("temperature")))
    dew_f = c_to_f(parse_metric_value(observation.get("dewpoint")))
    heat_index_f = c_to_f(parse_metric_value(observation.get("heatIndex")))
    wind_chill_f = c_to_f(parse_metric_value(observation.get("windChill")))

    feels_like_f = heat_index_f
    if feels_like_f is None:
        feels_like_f = wind_chill_f
    if feels_like_f is None:
        feels_like_f = temp_f

    wind_speed_mph = speed_to_mph(observation.get("windSpeed"))
    wind_gust_mph = speed_to_mph(observation.get("windGust"))

    humidity = parse_metric_value(observation.get("relativeHumidity"))
    wind_direction = parse_metric_value(observation.get("windDirection"))

    return {
        "ok": True,
        "source_provider": "NOAA",
        "location": {
            "label": str(location.get("label") or resolved["display_name"]),
            "display_name": resolved["display_name"],
            "city": str(location.get("city") or ""),
            "state": str(location.get("state") or ""),
            "zip": str(location.get("zip") or ""),
            "country": str(location.get("country") or "US"),
            "lat": round(lat, 4),
            "lon": round(lon, 4),
        },
        "current": {
            "temperature_f": round_or_none(temp_f),
            "feels_like_f": round_or_none(feels_like_f),
            "dew_point_f": round_or_none(dew_f),
            "humidity_percent": round_or_none(humidity),
            "wind_speed_mph": round_or_none(wind_speed_mph),
            "wind_gust_mph": round_or_none(wind_gust_mph),
            "wind_direction_degrees": round_or_none(wind_direction),
            "summary": str(observation.get("textDescription") or "n/a"),
            "observed_at": str(observation.get("timestamp") or ""),
        },
        "forecast": {
            "name": str((first_period or {}).get("name") or ""),
            "short": str((first_period or {}).get("shortForecast") or ""),
            "detailed": str((first_period or {}).get("detailedForecast") or ""),
            "icon_url": str((first_period or {}).get("icon") or ""),
        },
        "forecast_days": forecast_days,
        "map_url": build_map_url(lat, lon),
        "generated_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
    }


def main() -> int:
    try:
        if len(sys.argv) < 2:
            raise ValueError("Expected JSON location payload as first argument")

        if len(sys.argv) >= 3 and sys.argv[1] == "--location-b64":
            decoded = base64.b64decode(sys.argv[2].encode("ascii")).decode("utf-8")
            location = json.loads(decoded)
        else:
            location = json.loads(sys.argv[1])
        if not isinstance(location, dict):
            raise ValueError("Invalid location payload")

        entry_date = None
        if "--entry-date" in sys.argv:
            idx = sys.argv.index("--entry-date")
            if idx + 1 < len(sys.argv):
                entry_date = str(sys.argv[idx + 1] or "").strip()
        result = fetch_weather(location, entry_date=entry_date)
        print(json.dumps(result, separators=(",", ":")))
        return 0
    except Exception as exc:  # noqa: BLE001
        print(json.dumps({"ok": False, "error": str(exc)}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
