<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/bootstrap.php';
require_once dirname(__DIR__) . '/app/Auth/require_auth.php';

use App\Auth\Auth;
use App\Core\Database;
use App\Entry\EntryUid;
use App\Entry\EntryRepository;
use App\Security\Csrf;

$userId = Auth::userId();
$userTimeZone = Auth::timezonePreference() ?? date_default_timezone_get();
$interfaceTheme = Auth::interfaceTheme();

if (!is_int($userId)) {
    header('Location: /login.php');
    exit;
}

$userNow = new DateTimeImmutable('now', new DateTimeZone($userTimeZone));
$defaultEntryDate = $userNow->format('Y-m-d');
$defaultEntryTitle = $userNow->format('Y-m-d | l');

$error = null;
$entry = [
    'entry_uid' => '',
    'entry_date' => $defaultEntryDate,
    'weather_location_key' => 'new_richmond_wi',
    'weather_location_json' => [
        'key' => 'new_richmond_wi',
        'label' => 'New Richmond, WI, US',
        'city' => 'New Richmond',
        'state' => 'WI',
        'zip' => '54017',
        'country' => 'US',
    ],
    'title' => $defaultEntryTitle,
    'content_raw' => '',
    'word_count' => 0,
    'workflow_stage' => 'AUTOSAVE',
    'body_locked' => 0,
];
$calendarYear = (int) $userNow->format('Y');
$calendarMonth = (int) $userNow->format('n');
$calendarEntriesByDate = [];
$todayEntryUid = '';
$headingPrefix = '';
$metaGroups = [
    'group0' => null,
    'group1' => null,
    'group2' => null,
    'group3' => null,
];
$defaultWeatherPresets = [
    'new_york_us' => [
        'key' => 'new_york_us',
        'label' => 'New York, NY, US',
        'city' => 'New York',
        'state' => 'NY',
        'zip' => '10001',
        'country' => 'US',
    ],
    'chicago_us' => [
        'key' => 'chicago_us',
        'label' => 'Chicago, IL, US',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip' => '60601',
        'country' => 'US',
    ],
    'los_angeles_us' => [
        'key' => 'los_angeles_us',
        'label' => 'Los Angeles, CA, US',
        'city' => 'Los Angeles',
        'state' => 'CA',
        'zip' => '90001',
        'country' => 'US',
    ],
    'new_richmond_wi' => [
        'key' => 'new_richmond_wi',
        'label' => 'New Richmond, WI, US',
        'city' => 'New Richmond',
        'state' => 'WI',
        'zip' => '54017',
        'country' => 'US',
    ],
];
$entryWeatherOptions = $defaultWeatherPresets;

function sanitizeEntryWeatherLocation(array $input): array
{
    $label = trim((string) ($input['label'] ?? ''));
    $city = trim((string) ($input['city'] ?? ''));
    $state = trim((string) ($input['state'] ?? ''));
    $zip = trim((string) ($input['zip'] ?? ''));
    $country = strtoupper(trim((string) ($input['country'] ?? 'US')));
    if ($country === '') {
        $country = 'US';
    }
    if ($city === '') {
        $city = 'New Richmond';
    }
    if ($state === '') {
        $state = 'WI';
    }
    if ($zip === '') {
        $zip = '54017';
    }
    if ($label === '') {
        $label = $city . ', ' . $state . ', ' . $country;
    }

    return [
        'label' => $label,
        'city' => $city,
        'state' => $state,
        'zip' => $zip,
        'country' => $country,
    ];
}

function parseEntryWeatherCustomPresets(string $raw): array
{
    if (trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $normalized = [];
    foreach ($decoded as $key => $location) {
        if (!is_string($key) || !is_array($location)) {
            continue;
        }
        $normalized[$key] = sanitizeEntryWeatherLocation($location);
    }
    return $normalized;
}

function normalizeEntryWeatherSelectedKey(array $options, string $selectedKey): string
{
    if ($selectedKey !== '' && isset($options[$selectedKey])) {
        return $selectedKey;
    }
    return 'new_richmond_wi';
}

try {
    $pdo = Database::connection($config['database']);
    $repo = new EntryRepository($pdo, (string) ($config['entry_uid']['app_version_code'] ?? 'W010000'));

    $userStmt = $pdo->prepare('SELECT display_name FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute(['id' => $userId]);
    $userRow = $userStmt->fetch();
    if (is_array($userRow)) {
        $displayName = trim((string) ($userRow['display_name'] ?? ''));
        if ($displayName !== '') {
            $headingPrefix = $displayName . "'s ";
        }
    }

    $uidQueryProvided = isset($_GET['uid']) && is_string($_GET['uid']) && trim((string) $_GET['uid']) !== '';
    $requestedEntryUid = $uidQueryProvided && is_string($_GET['uid']) ? trim($_GET['uid']) : '';
    if ($requestedEntryUid !== '' && !EntryUid::isValid($requestedEntryUid)) {
        $requestedEntryUid = '';
        $error = 'Requested entry reference is invalid.';
    }
    if ($requestedEntryUid !== '') {
        $loaded = $repo->findByUidForUser($requestedEntryUid, $userId);
        if (is_array($loaded)) {
            $loadedLocationJson = $loaded['weather_location_json'] ?? null;
            $loadedLocation = [];
            if (is_array($loadedLocationJson)) {
                $loadedLocation = $loadedLocationJson;
            } elseif (is_string($loadedLocationJson) && trim($loadedLocationJson) !== '') {
                $decodedLocation = json_decode($loadedLocationJson, true);
                if (is_array($decodedLocation)) {
                    $loadedLocation = $decodedLocation;
                }
            }
            $entry = [
                'entry_uid' => (string) ($loaded['entry_uid'] ?? ''),
                'entry_date' => (string) ($loaded['entry_date'] ?? $entry['entry_date']),
                'weather_location_key' => trim((string) ($loaded['weather_location_key'] ?? $entry['weather_location_key'])),
                'weather_location_json' => sanitizeEntryWeatherLocation($loadedLocation),
                'title' => (string) ($loaded['title'] ?? ''),
                'content_raw' => (string) ($loaded['content_raw'] ?? ''),
                'word_count' => (int) ($loaded['word_count'] ?? 0),
                'workflow_stage' => (string) ($loaded['workflow_stage'] ?? 'AUTOSAVE'),
                'body_locked' => (int) ($loaded['body_locked'] ?? 0),
            ];
        } else {
            $error = 'Requested entry was not found.';
        }
    }

    $calendarDefaultYear = $calendarYear;
    $calendarDefaultMonth = $calendarMonth;
    if ((string) ($entry['entry_uid'] ?? '') !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($entry['entry_date'] ?? '')) === 1) {
        $entryDateObj = DateTimeImmutable::createFromFormat('Y-m-d', (string) $entry['entry_date']);
        if ($entryDateObj instanceof DateTimeImmutable) {
            $calendarDefaultYear = (int) $entryDateObj->format('Y');
            $calendarDefaultMonth = (int) $entryDateObj->format('n');
        }
    }

    $calendarYearInput = max(1970, min(2100, (int) ($_GET['calendar_year'] ?? $calendarDefaultYear)));
    $calendarMonthInput = max(1, min(12, (int) ($_GET['calendar_month'] ?? $calendarDefaultMonth)));
    $calendarYear = $calendarYearInput;
    $calendarMonth = $calendarMonthInput;

    $monthStart = DateTimeImmutable::createFromFormat('Y-n-j', $calendarYear . '-' . $calendarMonth . '-1');
    if (!$monthStart instanceof DateTimeImmutable) {
        $monthStart = new DateTimeImmutable($userNow->format('Y-m-01'));
    }
    $nextMonthStart = $monthStart->modify('first day of next month');

        $calendarStmt = $pdo->prepare(
                                'SELECT entry_uid, entry_date, title, word_count, workflow_stage
         FROM journal_entries
         WHERE user_id = :user_id
           AND entry_date >= :start_date
           AND entry_date < :next_date
         ORDER BY entry_date ASC, created_at ASC, id ASC'
    );
    $calendarStmt->execute([
        'user_id' => $userId,
        'start_date' => $monthStart->format('Y-m-d'),
        'next_date' => $nextMonthStart->format('Y-m-d'),
    ]);
    $calendarRows = $calendarStmt->fetchAll() ?: [];
    foreach ($calendarRows as $calendarRow) {
        if (!is_array($calendarRow)) {
            continue;
        }
        $dateKey = (string) ($calendarRow['entry_date'] ?? '');
        $uidValue = (string) ($calendarRow['entry_uid'] ?? '');
        if ($dateKey === '' || $uidValue === '') {
            continue;
        }

        if (!isset($calendarEntriesByDate[$dateKey])) {
            $stageValue = strtoupper(trim((string) ($calendarRow['workflow_stage'] ?? '')));
            $calendarEntriesByDate[$dateKey] = [
                'entry_uid' => $uidValue,
                'title' => (string) ($calendarRow['title'] ?? ''),
                'count' => 1,
                'total_words' => (int) ($calendarRow['word_count'] ?? 0),
                'is_finished' => $stageValue === 'COMPLETE' || $stageValue === 'FINAL',
            ];
            continue;
        }

        $stageValue = strtoupper(trim((string) ($calendarRow['workflow_stage'] ?? '')));
        $calendarEntriesByDate[$dateKey]['entry_uid'] = $uidValue;
        $calendarEntriesByDate[$dateKey]['title'] = (string) ($calendarRow['title'] ?? '');
        $calendarEntriesByDate[$dateKey]['count'] = ((int) ($calendarEntriesByDate[$dateKey]['count'] ?? 1)) + 1;
        $calendarEntriesByDate[$dateKey]['total_words'] = ((int) ($calendarEntriesByDate[$dateKey]['total_words'] ?? 0)) + (int) ($calendarRow['word_count'] ?? 0);
        $calendarEntriesByDate[$dateKey]['is_finished'] = $stageValue === 'COMPLETE' || $stageValue === 'FINAL';
    }

    $todayStmt = $pdo->prepare(
        'SELECT entry_uid
         FROM journal_entries
         WHERE user_id = :user_id AND entry_date = :entry_date
         ORDER BY created_at DESC, id DESC
         LIMIT 1'
    );
    $todayStmt->execute([
        'user_id' => $userId,
        'entry_date' => $defaultEntryDate,
    ]);
    $todayRow = $todayStmt->fetch();
    if (is_array($todayRow)) {
        $todayEntryUid = (string) ($todayRow['entry_uid'] ?? '');
    }

    if (!$uidQueryProvided && $todayEntryUid !== '') {
        $redirectParams = [
            'uid' => $todayEntryUid,
            'calendar_year' => $calendarYear,
            'calendar_month' => $calendarMonth,
        ];
        header('Location: /entry.php?' . http_build_query($redirectParams));
        exit;
    }

    $weatherStateStmt = $pdo->prepare('SELECT weather_presets_json, weather_selected_key FROM users WHERE id = :id LIMIT 1');
    $weatherStateStmt->execute(['id' => $userId]);
    $weatherState = $weatherStateStmt->fetch();
    if (is_array($weatherState)) {
        $customWeather = parseEntryWeatherCustomPresets((string) ($weatherState['weather_presets_json'] ?? ''));
        foreach ($customWeather as $key => $location) {
            $entryWeatherOptions[$key] = array_merge(['key' => $key], $location);
        }
        $fallbackWeatherKey = normalizeEntryWeatherSelectedKey($entryWeatherOptions, trim((string) ($weatherState['weather_selected_key'] ?? '')));
        $entryWeatherKey = normalizeEntryWeatherSelectedKey($entryWeatherOptions, trim((string) ($entry['weather_location_key'] ?? '')));
        if ($entryWeatherKey === '' || !isset($entryWeatherOptions[$entryWeatherKey])) {
            $entryWeatherKey = $fallbackWeatherKey;
        }
        $entry['weather_location_key'] = $entryWeatherKey;
        if (!is_array($entry['weather_location_json'] ?? null) || trim((string) (($entry['weather_location_json']['label'] ?? ''))) === '') {
            $entry['weather_location_json'] = sanitizeEntryWeatherLocation($entryWeatherOptions[$entryWeatherKey]);
        }
        if (!is_array($entry['weather_location_json'] ?? null)) {
            $entry['weather_location_json'] = sanitizeEntryWeatherLocation($entryWeatherOptions[$entryWeatherKey]);
        }
        $entry['weather_location_json']['key'] = $entryWeatherKey;
    }

    $isCompleteEntry = strtoupper((string) ($entry['workflow_stage'] ?? '')) === 'COMPLETE';
    if ($isCompleteEntry && (string) ($entry['entry_uid'] ?? '') !== '') {
        $metaStmt0 = $pdo->prepare(
            'SELECT entry_title, created_datetime, modified_datetime, entry_hash_sha256, updated_at
             FROM entry_meta_group_0
             WHERE entry_uid = :entry_uid
             LIMIT 1'
        );
        $metaStmt0->execute(['entry_uid' => (string) $entry['entry_uid']]);
        $meta0 = $metaStmt0->fetch();
        $metaGroups['group0'] = is_array($meta0) ? $meta0 : null;

        $metaStmt1 = $pdo->prepare(
            'SELECT word_count, reading_time_minutes, flesch_reading_ease, flesch_kincaid_grade, gunning_fog, smog_index,
                    automated_readability_index, dale_chall, average_word_length, long_word_ratio, thought_fragmentation, updated_at
             FROM entry_meta_group_1
             WHERE entry_uid = :entry_uid
             LIMIT 1'
        );
        $metaStmt1->execute(['entry_uid' => (string) $entry['entry_uid']]);
        $meta1 = $metaStmt1->fetch();
        $metaGroups['group1'] = is_array($meta1) ? $meta1 : null;

        $metaStmt2 = $pdo->prepare(
            'SELECT llm_model, analysis_json, updated_at
             FROM entry_meta_group_2
             WHERE entry_uid = :entry_uid
             LIMIT 1'
        );
        $metaStmt2->execute(['entry_uid' => (string) $entry['entry_uid']]);
        $meta2 = $metaStmt2->fetch();
        if (is_array($meta2)) {
            $analysisJson = json_decode((string) ($meta2['analysis_json'] ?? '{}'), true);
            $meta2['analysis_json'] = is_array($analysisJson) ? $analysisJson : [];
            $metaGroups['group2'] = $meta2;
        }

        $metaStmt3 = $pdo->prepare(
            'SELECT location_label, location_city, location_state, location_zip, location_country,
                    current_summary, current_temperature_f, current_feels_like_f, current_humidity_percent,
                    current_wind_speed_mph, observed_at, forecast_name, forecast_short, map_url, updated_at
             FROM entry_meta_group_3
             WHERE entry_uid = :entry_uid
             LIMIT 1'
        );
        $metaStmt3->execute(['entry_uid' => (string) $entry['entry_uid']]);
        $meta3 = $metaStmt3->fetch();
        $metaGroups['group3'] = is_array($meta3) ? $meta3 : null;
    }

} catch (Throwable $throwable) {
    $error = 'Unable to load editor data right now.';
}

$csrfToken = Csrf::token();

// Editor toolbar options (configurable)
$editorToolbarOptions = [
    'bold' => 'Bold',
    'italic' => 'Italic',
    'underline' => 'Underline',
    'strikeThrough' => 'Strike',
    'heading' => 'Headings',
    'ul' => 'Bulleted List',
    'ol' => 'Numbered List',
];
// Non-configurable buttons: Full Screen, Time

// Load editor settings
$editorSettings = [
    'toolbar' => array_keys($editorToolbarOptions),
];
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->prepare('SELECT editor_settings_json FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (is_array($row) && isset($row['editor_settings_json']) && $row['editor_settings_json']) {
            $json = json_decode($row['editor_settings_json'], true);
            if (is_array($json) && isset($json['toolbar']) && is_array($json['toolbar'])) {
                $editorSettings['toolbar'] = $json['toolbar'];
            }
        }
    }
} catch (Throwable $e) {
    // Ignore toolbar settings load errors, fallback to default
}

$entryJson = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($entryJson)) {
    $entryJson = '{}';
}
$entryWeatherOptionsJson = json_encode($entryWeatherOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($entryWeatherOptionsJson)) {
    $entryWeatherOptionsJson = '{}';
}
$editorToolbarOptionsJson = json_encode($editorToolbarOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($editorToolbarOptionsJson)) {
    $editorToolbarOptionsJson = '{}';
}
$editorToolbarButtonsJson = json_encode($editorSettings['toolbar'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($editorToolbarButtonsJson)) {
    $editorToolbarButtonsJson = '[]';
}

function formatLocalDateTime(?string $raw, string $targetTimeZone): string
{
    $value = is_string($raw) ? trim($raw) : '';
    if ($value === '') {
        return '';
    }

    try {
        $utc = new DateTimeZone('UTC');
        $target = new DateTimeZone($targetTimeZone);
        $utcDate = new DateTimeImmutable($value, $utc);
        return $utcDate->setTimezone($target)->format('Y-m-d h:i:s A T');
    } catch (Throwable) {
        return $value;
    }
}

function renderListValues(mixed $value): string
{
    if (!is_array($value) || count($value) === 0) {
        return 'n/a';
    }

    $clean = [];
    foreach ($value as $item) {
        if (is_scalar($item)) {
            $text = trim((string) $item);
            if ($text !== '') {
                $clean[] = $text;
            }
        }
    }

    return count($clean) > 0 ? implode(', ', $clean) : 'n/a';
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>rJournaler Web - Entry</title>
    <style>
        :root {
            --font-ui: "Segoe UI", "Aptos", "Trebuchet MS", sans-serif;
            --font-mono: Consolas, "Cascadia Mono", "Courier New", monospace;
            --radius-md: 10px;
            --shadow-sm: 0 4px 12px rgba(17, 24, 39, 0.08);
        }

        body[data-theme="light"] {
            --bg: #f7f9fc;
            --bg-accent: #edf3fb;
            --surface: #ffffff;
            --surface-soft: #f7faff;
            --border: #d6e0ef;
            --text: #1f2f43;
            --text-muted: #5b6f87;
            --heading: #152334;
            --link: #1f5f9a;
            --ok: #0a7a32;
            --warn: #9a6f12;
            --danger: #842533;
            --control-btn-bg: #e8eef6;
            --control-btn-border: #9aabc2;
            --control-btn-text: #334b63;
            --control-btn-disabled-bg: #eef3fa;
            --control-btn-disabled-border: #c3cfde;
            --control-btn-disabled-text: #8192a7;
            --meta-shell-border: #d9e3f0;
            --meta-shell-bg: #f8fbff;
            --meta-tab-border: #9eb8db;
            --meta-tab-bg: #eff5ff;
            --meta-tab-text: #1e3a5f;
            --meta-tab-active-bg: #1e3a5f;
            --meta-tab-active-text: #ffffff;
            --meta-row-term: #243a57;
            --meta-section-border: #dce7f7;
            --meta-section-bg: #ffffff;
            --meta-subtitle: #173457;
            --meta-tag-border: #cddbf1;
            --meta-tag-bg: #f5f9ff;
            --meta-tag-text: #1d3d63;
            --meta-table-border: #e6edf7;
            --meta-table-head-bg: #f3f8ff;
            --meta-table-head-text: #1f3f63;
            --meta-note-text: #61748b;
            --link-disabled: #8a98a8;
            --goal-pending: #b00020;
            --calendar-cell-border: #e1e1e1;
            --calendar-head-bg: #f6f6f6;
            --calendar-empty-bg: #fafafa;
            --calendar-has-entry-bg: #eaf5ff;
            --calendar-has-entry-pending-bg: #ececec;
            --calendar-selected-bg: #fff1a8;
            --calendar-entry-link: #0d4f8b;
            --calendar-entry-pending-link: #2f4052;
            --day-badge-border: #b7c7db;
            --day-badge-bg: #f1f6fc;
            --day-badge-text: #294a6f;
            --calendar-legend-text: #4b5a6b;
            --legend-dot-border: #9ba8b8;
            --legend-dot-finished-bg: #eaf5ff;
            --legend-dot-pending-bg: #ececec;
            --modal-overlay-bg: rgba(0, 0, 0, 0.45);
            --modal-card-bg: #ffffff;
            --modal-card-border: #d0d0d0;
            --modal-card-shadow: 0 20px 45px rgba(0, 0, 0, 0.24);
        }

        body[data-theme="neutral"] {
            --bg: #f4f3f1;
            --bg-accent: #eceae6;
            --surface: #fffdf8;
            --surface-soft: #f4f1ea;
            --border: #d8d1c5;
            --text: #2f3432;
            --text-muted: #676d69;
            --heading: #222827;
            --link: #3c5f72;
            --ok: #2f6f42;
            --warn: #8a6f2c;
            --danger: #6f3232;
            --control-btn-bg: #ebeae6;
            --control-btn-border: #aeb1ac;
            --control-btn-text: #3f4944;
            --control-btn-disabled-bg: #efede8;
            --control-btn-disabled-border: #c6c4bf;
            --control-btn-disabled-text: #838983;
            --meta-shell-border: #d8d1c5;
            --meta-shell-bg: #f4f1ea;
            --meta-tab-border: #b9aea0;
            --meta-tab-bg: #efebe3;
            --meta-tab-text: #4c4f48;
            --meta-tab-active-bg: #4c4f48;
            --meta-tab-active-text: #fffdf8;
            --meta-row-term: #3f4944;
            --meta-section-border: #d8d1c5;
            --meta-section-bg: #fffdf8;
            --meta-subtitle: #4a514d;
            --meta-tag-border: #cdc5b8;
            --meta-tag-bg: #f2eee6;
            --meta-tag-text: #4d5550;
            --meta-table-border: #ded7cb;
            --meta-table-head-bg: #f0ece4;
            --meta-table-head-text: #4a514d;
            --meta-note-text: #6b716d;
            --link-disabled: #8b8f8b;
            --goal-pending: #8a2f2f;
            --calendar-cell-border: #d6d0c4;
            --calendar-head-bg: #ece8df;
            --calendar-empty-bg: #f6f2ea;
            --calendar-has-entry-bg: #e8efe7;
            --calendar-has-entry-pending-bg: #ebe7df;
            --calendar-selected-bg: #efe5b7;
            --calendar-entry-link: #3c5f72;
            --calendar-entry-pending-link: #4d5550;
            --day-badge-border: #bdb4a8;
            --day-badge-bg: #ece8df;
            --day-badge-text: #4d5550;
            --calendar-legend-text: #666b67;
            --legend-dot-border: #9f9b93;
            --legend-dot-finished-bg: #e8efe7;
            --legend-dot-pending-bg: #ebe7df;
            --modal-overlay-bg: rgba(22, 18, 12, 0.42);
            --modal-card-bg: #fffdf8;
            --modal-card-border: #cbc3b5;
            --modal-card-shadow: 0 20px 45px rgba(31, 25, 17, 0.24);
        }

        body[data-theme="dark"] {
            --bg: #171d23;
            --bg-accent: #1e2730;
            --surface: #222d38;
            --surface-soft: #263340;
            --border: #364757;
            --text: #dbe4ec;
            --text-muted: #98a9b9;
            --heading: #f1f5f8;
            --link: #8fc3f3;
            --ok: #78c79a;
            --warn: #d2be7b;
            --danger: #f0b5bf;
            --control-btn-bg: #3a4754;
            --control-btn-border: #6f8193;
            --control-btn-text: #dde6ef;
            --control-btn-disabled-bg: #2b3641;
            --control-btn-disabled-border: #4a5a69;
            --control-btn-disabled-text: #8092a3;
            --shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.28);
            --meta-shell-border: #3a4a59;
            --meta-shell-bg: #1e2934;
            --meta-tab-border: #50657a;
            --meta-tab-bg: #253443;
            --meta-tab-text: #c9d9e9;
            --meta-tab-active-bg: #516b84;
            --meta-tab-active-text: #eaf2f9;
            --meta-row-term: #bfcedc;
            --meta-section-border: #3b4c5c;
            --meta-section-bg: #212d39;
            --meta-subtitle: #b2c6d9;
            --meta-tag-border: #4a6075;
            --meta-tag-bg: #2a3948;
            --meta-tag-text: #c5d7e7;
            --meta-table-border: #37495b;
            --meta-table-head-bg: #2b3a49;
            --meta-table-head-text: #c8d8e8;
            --meta-note-text: #9fb2c4;
            --link-disabled: #7f90a0;
            --goal-pending: #e4a1ab;
            --calendar-cell-border: #3b4a59;
            --calendar-head-bg: #2a3948;
            --calendar-empty-bg: #202b35;
            --calendar-has-entry-bg: #274154;
            --calendar-has-entry-pending-bg: #2a3138;
            --calendar-selected-bg: #6f6031;
            --calendar-entry-link: #9dd0ff;
            --calendar-entry-pending-link: #b8c7d4;
            --day-badge-border: #557087;
            --day-badge-bg: #2a3c4c;
            --day-badge-text: #b6ccde;
            --calendar-legend-text: #9db0c2;
            --legend-dot-border: #667b8f;
            --legend-dot-finished-bg: #274154;
            --legend-dot-pending-bg: #2a3138;
            --modal-overlay-bg: rgba(5, 10, 15, 0.62);
            --modal-card-bg: #1f2b36;
            --modal-card-border: #3b4c5c;
            --modal-card-shadow: 0 20px 45px rgba(0, 0, 0, 0.45);
        }

        body {
            font-family: var(--font-ui);
            margin: 0;
            padding: 1rem;
            background: radial-gradient(circle at 18% 0%, var(--bg-accent), var(--bg) 38%);
            color: var(--text);
            overflow-x: hidden;
        }
        h1, h2, h3 { color: var(--heading); }
        a { color: var(--link); text-decoration: none; }
        a:hover { text-decoration: underline; }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 0.8rem;
            flex-wrap: wrap;
            margin-bottom: 0.55rem;
        }

        .page-header h1 {
            margin: 0;
        }

        .header-links {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .pill {
            display: inline-block;
            padding: 0.12rem 0.52rem;
            border-radius: 999px;
            font-size: 0.82rem;
            border: 1px solid var(--border);
            background: var(--surface-soft);
            color: var(--text);
            box-shadow: var(--shadow-sm);
        }

        /* Manual mode override to force mobile UI without device media detection. */
        body.mode-mobile .editor-stats-row { font-size: 0.82rem; }
        body.mode-mobile .editor-toolbar .toolbar-mobile-only { display: inline-flex; }
        body.mode-mobile .editor-toolbar .toolbar-status { display: inline-flex; font-size: 0.9rem; }
        body.mode-mobile #save-status { display: none; }
        body.mode-mobile .editor-block,
        body.mode-mobile .editor-shell,
        body.mode-mobile .editor-stats-row { max-width: 98vw; }
        body.mode-mobile .editor-toolbar button { padding: 0.32rem 0.52rem; }

        html.mode-mobile.editor-mobile-fullscreen,
        body.mode-mobile.editor-mobile-fullscreen {
            overflow: hidden;
            width: 100%;
            height: 100%;
        }

        body.mode-mobile.editor-mobile-fullscreen .editor-block {
            position: fixed;
            inset: 0;
            z-index: 2000;
            width: 100vw;
            max-width: 100vw;
            margin: 0;
            padding: 0;
            background: var(--surface);
            display: flex;
            flex-direction: column;
            --mobile-toolbar-height: 3.15rem;
            --mobile-stats-height: 2.45rem;
            height: var(--editor-mobile-fullscreen-height, 100svh);
            min-height: var(--editor-mobile-fullscreen-height, 100svh);
            max-height: var(--editor-mobile-fullscreen-height, 100svh);
            overflow: hidden;
            contain: strict;
        }
        body.mode-mobile.editor-mobile-fullscreen .editor-block > label { display: none; }
        body.mode-mobile.editor-mobile-fullscreen .editor-shell {
            max-width: none;
            margin: 0;
            flex: 1;
            display: grid;
            grid-template-rows: var(--mobile-toolbar-height) minmax(0, 1fr);
            border-radius: 0;
            border-left: 0;
            border-right: 0;
        }
        body.mode-mobile.editor-mobile-fullscreen .editor-toolbar,
        body.mode-mobile.editor-mobile-fullscreen .editor-stats-row { flex-shrink: 0; }
        body.mode-mobile.editor-mobile-fullscreen .editor-toolbar {
            height: var(--mobile-toolbar-height);
            min-height: var(--mobile-toolbar-height);
            max-height: var(--mobile-toolbar-height);
            box-sizing: border-box;
            flex-wrap: nowrap;
            align-items: center;
            align-content: center;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
            contain: layout paint;
        }
        body.mode-mobile.editor-mobile-fullscreen .editor-toolbar button { flex: 0 0 auto; }
        body.mode-mobile.editor-mobile-fullscreen .editor-toolbar .toolbar-status {
            flex: 0 0 5.2rem;
            width: 5.2rem;
            min-width: 5.2rem;
            max-width: 5.2rem;
        }
        body.mode-mobile.editor-mobile-fullscreen .editor-surface {
            height: auto;
            max-height: none;
            min-height: 0;
            flex: 1;
            overflow-y: scroll;
            overflow-x: hidden;
            scrollbar-gutter: stable both-edges;
            overscroll-behavior: contain;
            overflow-wrap: anywhere;
            word-break: break-word;
            contain: layout paint;
        }
        body.mode-mobile.editor-mobile-fullscreen .editor-stats-row {
            max-width: none;
            margin: 0;
            padding: 0.55rem 0.65rem;
            background: var(--surface-soft);
            border-top: 1px solid var(--border);
            height: var(--mobile-stats-height);
            min-height: var(--mobile-stats-height);
            max-height: var(--mobile-stats-height);
            box-sizing: border-box;
            white-space: nowrap;
            overflow: hidden;
            align-items: center;
            contain: layout paint;
        }

        body.mode-mobile.mode-mobile-portrait { padding: 0.55rem; }
        body.mode-mobile.mode-mobile-portrait .panel { padding: 0.55rem; }
        body.mode-mobile.mode-mobile-portrait .layout,
        body.mode-mobile.mode-mobile-portrait .editor-block,
        body.mode-mobile.mode-mobile-portrait .editor-shell,
        body.mode-mobile.mode-mobile-portrait .editor-stats-row {
            width: 100%;
            max-width: 100%;
        }
        body.mode-mobile.mode-mobile-portrait .editor-toolbar {
            gap: 0.25rem;
            padding: 0.35rem;
            min-height: 4.6rem;
            align-content: start;
        }
        body.mode-mobile.mode-mobile-portrait.editor-mobile-fullscreen .editor-toolbar {
            min-height: var(--mobile-toolbar-height);
            align-content: center;
        }
        body.mode-mobile.mode-mobile-portrait.editor-mobile-fullscreen .editor-toolbar button[data-cmd="insertUnorderedList"],
        body.mode-mobile.mode-mobile-portrait.editor-mobile-fullscreen .editor-toolbar button[data-cmd="insertOrderedList"] {
            display: none;
        }
        body.mode-mobile.mode-mobile-portrait .editor-toolbar button {
            padding: 0.26rem 0.4rem;
            font-size: 0.82rem;
        }
        body.mode-mobile.mode-mobile-portrait .editor-toolbar .toolbar-heading-button { display: none; }
        body.mode-mobile.mode-mobile-portrait .editor-toolbar .toolbar-heading-select { display: inline-block; }
        body.mode-mobile.mode-mobile-portrait .editor-toolbar .toolbar-status {
            min-width: 5.2rem;
            font-size: 0.95rem;
        }
        body.mode-mobile.mode-mobile-portrait .editor-stats-row {
            gap: 0.3rem;
            padding-inline: 0.45rem;
            font-size: 0.76rem;
        }

        .layout { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 1rem; }
        @media (max-width: 960px) { .layout { grid-template-columns: 1fr; } }
        @media (min-width: 961px) {
            .layout { align-items: stretch; }
            .layout > .panel { height: 100%; }
            .layout > .panel:first-child {
                display: flex;
                flex-direction: column;
                min-height: 0;
            }
            .sidebar {
                height: 100%;
                align-content: stretch;
                grid-template-rows: auto 1fr;
            }
            .sidebar > .panel:last-child { height: 100%; }
            .editor-block,
            .editor-shell,
            .editor-stats-row {
                width: 100%;
                max-width: none;
                margin-left: 0;
                margin-right: 0;
            }
            .editor-block {
                flex: 1;
                min-height: 0;
                display: flex;
                flex-direction: column;
            }
            .editor-shell {
                flex: 1;
                min-height: 0;
                display: grid;
                grid-template-rows: auto minmax(0, 1fr);
            }
            .editor-surface {
                height: auto;
                min-height: 0;
                max-height: none;
                overflow-y: auto;
            }
        }
        .panel { border: 1px solid var(--border); border-radius: 8px; padding: 0.75rem; background: var(--surface); box-shadow: var(--shadow-sm); }
        .sidebar { display: grid; gap: 1rem; align-content: start; }
        input[type="text"], input[type="date"], textarea { width: 100%; box-sizing: border-box; margin-bottom: 0.5rem; }
        input[type="text"], input[type="date"], select, textarea {
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface-soft);
            color: var(--text);
            padding: 0.4rem 0.5rem;
        }
        button {
            border: 1px solid var(--control-btn-border);
            border-radius: 8px;
            background: var(--control-btn-bg);
            color: var(--control-btn-text);
            font-weight: 600;
            cursor: pointer;
        }
        button:hover { filter: brightness(1.05); }
        button:disabled {
            background: var(--control-btn-disabled-bg);
            border-color: var(--control-btn-disabled-border);
            color: var(--control-btn-disabled-text);
            cursor: not-allowed;
            filter: none;
            opacity: 1;
        }
        textarea { min-height: 420px; }
        .entry-header-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.55rem; }
        @media (max-width: 760px) { .entry-header-row { grid-template-columns: 1fr; } }
        .field-group label { display: block; }
        .editor-block { max-width: 800px; margin: 0 auto; }
        .editor-shell { border: 1px solid var(--border); border-radius: 8px; overflow: hidden; max-width: 800px; margin: 0 auto; }
        .editor-toolbar { display: flex; flex-wrap: wrap; gap: 0.35rem; padding: 0.45rem; background: var(--surface-soft); border-bottom: 1px solid var(--border); }
        .editor-toolbar button { padding: 0.2rem 0.45rem; }
        .editor-toolbar .toolbar-time-button { margin-left: 0; }
        .editor-toolbar .toolbar-mobile-only { display: none; }
        .editor-toolbar .toolbar-heading-select {
            display: none;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface);
            color: var(--text);
            padding: 0.2rem 0.35rem;
            font-size: 0.82rem;
            height: 1.95rem;
        }
        .editor-toolbar .toolbar-status {
            display: none;
            align-items: center;
            margin-left: auto;
            margin-right: auto;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-muted);
            white-space: nowrap;
            min-width: 4.6rem;
            justify-content: center;
            text-align: center;
        }
        .editor-toolbar .toolbar-status.ok { color: var(--ok); }
        .editor-toolbar .toolbar-status.error { color: var(--danger); }
        .editor-surface { min-height: 320px; max-height: 60vh; height: 40vh; padding: 0.65rem; background: var(--surface); overflow-y: auto; overflow-wrap: break-word; word-break: normal; white-space: pre-wrap; cursor: text; user-select: text; -webkit-user-select: text; font-family: var(--font-mono); line-height: 1.45; }

        @media (max-width: 600px) {
            .editor-shell { max-width: 98vw; margin: 0.5rem auto; }
            .editor-surface { min-height: 180px; height: 32vh; max-height: 50vh; font-size: 1rem; }
        }
        .editor-surface h1, .editor-surface h2, .editor-surface h3, .editor-surface h4, .editor-surface h5, .editor-surface h6 { margin: 0.35rem 0; }
        .editor-surface p { margin: 0.35rem 0; }
        .editor-surface ul, .editor-surface ol { margin: 0.35rem 0 0.35rem 1.2rem; }
        .editor-surface[contenteditable="false"] { background: var(--surface-soft); color: var(--text-muted); }
        .editor-stats-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            width: 100%;
            max-width: 800px;
            margin: 0.45rem auto 0.2rem;
            padding-inline: 0.65rem;
            font-size: 0.9rem;
            color: var(--text-muted);
            box-sizing: border-box;
        }
        .editor-stats-wordcount,
        .editor-stats-goal,
        .editor-stats-readtime {
            flex: 1;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .editor-stats-wordcount { text-align: left; }
        .editor-stats-goal { text-align: center; }
        .editor-stats-readtime { text-align: right; }
        #word-count,
        #word-goal-status,
        #read-time {
            font-weight: 700;
        }
        @media (hover: none) and (pointer: coarse) {
            .editor-stats-row { font-size: 0.82rem; }
            .editor-toolbar .toolbar-mobile-only { display: inline-flex; }
            .editor-toolbar .toolbar-status { display: inline-flex; }
            #save-status { display: none; }
            .editor-toolbar .toolbar-status {
                font-size: 0.9rem;
            }
        }
        @media (max-width: 600px) {
            .editor-block,
            .editor-shell,
            .editor-stats-row {
                max-width: 98vw;
            }
            .editor-toolbar button {
                padding: 0.32rem 0.52rem;
            }
        }
        html.editor-mobile-fullscreen,
        body.editor-mobile-fullscreen {
            overflow: hidden;
            width: 100%;
            height: 100%;
        }
        @media (hover: none) and (pointer: coarse) {
            body.editor-mobile-fullscreen .editor-block {
                position: fixed;
                inset: 0;
                z-index: 2000;
                width: 100vw;
                max-width: 100vw;
                margin: 0;
                padding: 0;
                background: var(--surface);
                display: flex;
                flex-direction: column;
                --mobile-toolbar-height: 3.15rem;
                --mobile-stats-height: 2.45rem;
                height: var(--editor-mobile-fullscreen-height, 100svh);
                min-height: var(--editor-mobile-fullscreen-height, 100svh);
                max-height: var(--editor-mobile-fullscreen-height, 100svh);
                overflow: hidden;
                contain: strict;
            }
            body.editor-mobile-fullscreen .editor-block > label {
                display: none;
            }
            body.editor-mobile-fullscreen .editor-shell {
                max-width: none;
                margin: 0;
                flex: 1;
                display: grid;
                grid-template-rows: var(--mobile-toolbar-height) minmax(0, 1fr);
                border-radius: 0;
                border-left: 0;
                border-right: 0;
            }
            body.editor-mobile-fullscreen .editor-toolbar,
            body.editor-mobile-fullscreen .editor-stats-row {
                flex-shrink: 0;
            }
            body.editor-mobile-fullscreen .editor-toolbar {
                height: var(--mobile-toolbar-height);
                min-height: var(--mobile-toolbar-height);
                max-height: var(--mobile-toolbar-height);
                box-sizing: border-box;
                flex-wrap: nowrap;
                align-items: center;
                align-content: center;
                overflow-x: auto;
                overflow-y: hidden;
                -webkit-overflow-scrolling: touch;
                contain: layout paint;
            }
            body.editor-mobile-fullscreen .editor-toolbar button {
                flex: 0 0 auto;
            }
            body.editor-mobile-fullscreen .editor-toolbar .toolbar-status {
                flex: 0 0 5.2rem;
                width: 5.2rem;
                min-width: 5.2rem;
                max-width: 5.2rem;
            }
            body.editor-mobile-fullscreen .editor-surface {
                height: auto;
                max-height: none;
                min-height: 0;
                flex: 1;
                overflow-y: scroll;
                overflow-x: hidden;
                scrollbar-gutter: stable both-edges;
                overscroll-behavior: contain;
                overflow-wrap: anywhere;
                word-break: break-word;
                contain: layout paint;
            }
            body.editor-mobile-fullscreen .editor-stats-row {
                max-width: none;
                margin: 0;
                padding: 0.55rem 0.65rem;
                background: var(--surface-soft);
                border-top: 1px solid var(--border);
                height: var(--mobile-stats-height);
                min-height: var(--mobile-stats-height);
                max-height: var(--mobile-stats-height);
                box-sizing: border-box;
                white-space: nowrap;
                overflow: hidden;
                align-items: center;
                contain: layout paint;
            }
        }
        @media (hover: none) and (pointer: coarse) and (orientation: portrait) {
            body {
                padding: 0.55rem;
            }
            .panel {
                padding: 0.55rem;
            }
            .layout,
            .editor-block,
            .editor-shell,
            .editor-stats-row {
                width: 100%;
                max-width: 100%;
            }
            .editor-toolbar {
                gap: 0.25rem;
                padding: 0.35rem;
                min-height: 4.6rem;
                align-content: start;
            }
            body.editor-mobile-fullscreen .editor-toolbar {
                min-height: var(--mobile-toolbar-height);
                align-content: center;
            }
            body.editor-mobile-fullscreen .editor-toolbar button[data-cmd="insertUnorderedList"],
            body.editor-mobile-fullscreen .editor-toolbar button[data-cmd="insertOrderedList"] {
                display: none;
            }
            .editor-toolbar button {
                padding: 0.26rem 0.4rem;
                font-size: 0.82rem;
            }
            .editor-toolbar .toolbar-heading-button {
                display: none;
            }
            .editor-toolbar .toolbar-heading-select {
                display: inline-block;
            }
            .editor-toolbar .toolbar-status {
                min-width: 5.2rem;
                font-size: 0.95rem;
            }
            .editor-stats-row {
                gap: 0.3rem;
                padding-inline: 0.45rem;
                font-size: 0.76rem;
            }
        }
        @media (min-width: 961px) {
            .layout {
                align-items: start;
                height: auto;
                min-height: 0;
            }
            .layout > .panel {
                height: auto;
                min-height: 0;
            }
            .layout > .panel:first-child {
                display: block;
                overflow: visible;
            }
            .sidebar {
                height: auto;
                align-content: start;
                grid-template-rows: none;
                min-height: 0;
            }
            .sidebar > .panel:last-child {
                height: auto;
                min-height: 0;
            }
            .editor-block,
            .editor-shell,
            .editor-stats-row {
                width: 100%;
                max-width: none;
                margin-left: 0;
                margin-right: 0;
            }
            .editor-block {
                flex: none;
                min-height: 0;
                display: block;
            }
            .editor-shell {
                height: var(--desktop-editor-shell-height, auto);
                min-height: var(--desktop-editor-shell-height, auto);
                max-height: var(--desktop-editor-shell-height, auto);
                display: grid;
                grid-template-rows: auto minmax(0, 1fr);
            }
            .editor-surface {
                height: auto;
                min-height: 0;
                max-height: none;
                overflow-y: auto;
            }
        }
        .muted { color: var(--text-muted); }
        .error { color: var(--danger); }
        .ok { color: var(--ok); }
        ul { padding-left: 1rem; }
        li { margin-bottom: 0.25rem; }
        .meta-shell { border: 1px solid var(--meta-shell-border); border-radius: 10px; background: var(--meta-shell-bg); padding: 0.6rem; }
        .meta-fold {
            margin: 0.75rem 0;
            padding: 0;
            overflow: hidden;
        }
        .meta-fold[hidden] { display: none; }
        .meta-fold-summary {
            cursor: pointer;
            font-weight: 700;
            list-style: none;
            padding: 0.55rem 0.65rem;
            user-select: none;
            border-bottom: 1px solid var(--border);
            color: var(--heading);
            background: var(--surface-soft);
        }
        .meta-fold-summary::-webkit-details-marker { display: none; }
        .meta-fold-summary::before {
            content: '▸';
            display: inline-block;
            margin-right: 0.45rem;
            transition: transform 0.15s ease;
        }
        .meta-fold[open] .meta-fold-summary::before {
            transform: rotate(90deg);
        }
        .meta-fold .meta-shell {
            border: 0;
            border-radius: 0;
            background: transparent;
            padding: 0.6rem;
        }
        .meta-header { margin: 0 0 0.5rem; font-size: 1rem; }
        .meta-tabs { display: flex; gap: 0.4rem; margin-bottom: 0.55rem; flex-wrap: wrap; }
        .meta-tab { border: 1px solid var(--meta-tab-border); background: var(--meta-tab-bg); color: var(--meta-tab-text); padding: 0.25rem 0.6rem; border-radius: 999px; cursor: pointer; }
        .meta-tab.active { background: var(--meta-tab-active-bg); color: var(--meta-tab-active-text); border-color: var(--meta-tab-active-bg); }
        .meta-pane { display: none; }
        .meta-pane.active { display: block; }
        .meta-grid { display: grid; grid-template-columns: 1fr; gap: 0.35rem; }
        .meta-row { display: grid; grid-template-columns: 145px minmax(0, 1fr); gap: 0.5rem; align-items: start; }
        .meta-row dt { font-weight: 600; color: var(--meta-row-term); }
        .meta-row dd { margin: 0; word-break: break-word; }
        .meta-section { border: 1px solid var(--meta-section-border); border-radius: 8px; padding: 0.5rem; background: var(--meta-section-bg); margin-bottom: 0.5rem; }
        .meta-subtitle { margin: 0 0 0.4rem; font-size: 0.9rem; color: var(--meta-subtitle); }
        .meta-tags { display: flex; flex-wrap: wrap; gap: 0.35rem; }
        .meta-tag { border: 1px solid var(--meta-tag-border); background: var(--meta-tag-bg); color: var(--meta-tag-text); border-radius: 999px; padding: 0.15rem 0.48rem; font-size: 0.8rem; }
        .meta-table-wrap { overflow-x: auto; }
        .meta-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .meta-table th, .meta-table td { border-bottom: 1px solid var(--meta-table-border); padding: 0.42rem 0.45rem; text-align: left; vertical-align: top; }
        .meta-table th { background: var(--meta-table-head-bg); color: var(--meta-table-head-text); font-weight: 600; }
        .meta-table td.meta-note { color: var(--meta-note-text); font-size: 0.82rem; }
        .stat-line { margin: 0 0 0.4rem; }
        .action-stack { display: grid; gap: 0.45rem; margin-top: 0.65rem; }
        .action-stack button { width: 100%; }
        .action-stack button { border-radius: 999px; padding: 0.42rem 0.7rem; }
        .link-disabled { color: var(--link-disabled); pointer-events: none; text-decoration: none; }
        .goal-status { font-weight: 600; }
        .goal-status.pending { color: var(--goal-pending); }
        .goal-status.met { color: var(--ok); }
        .calendar-controls { display: grid; grid-template-columns: 1fr 1fr; gap: 0.45rem; margin-bottom: 0.55rem; }
        .calendar-grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .calendar-grid th, .calendar-grid td { width: 14.285%; min-width: 0; border: 1px solid var(--calendar-cell-border); text-align: center; vertical-align: top; padding: 0.35rem; height: 2rem; }
        .calendar-grid th { background: var(--calendar-head-bg); font-size: 0.82rem; }
        .calendar-grid td.empty { background: var(--calendar-empty-bg); }
        .calendar-grid td.has-entry { background: var(--calendar-has-entry-bg); font-weight: 600; }
        .calendar-grid td.has-entry-pending { background: var(--calendar-has-entry-pending-bg); font-weight: 600; }
        .calendar-grid td.selected-day { background: var(--calendar-selected-bg); }
        .calendar-grid td.has-entry a { color: var(--calendar-entry-link); text-decoration: none; }
        .calendar-grid td.has-entry a:hover { text-decoration: underline; }
        .calendar-grid td.has-entry-pending a { color: var(--calendar-entry-pending-link); text-decoration: none; }
        .calendar-grid td.has-entry-pending a:hover { text-decoration: underline; }
        .day-wrap { display: grid; gap: 0.18rem; justify-items: center; width: 100%; }
        .day-badges { display: flex; gap: 0.2rem; flex-wrap: wrap; justify-content: center; }
        .day-badge { border: 1px solid var(--day-badge-border); border-radius: 999px; font-size: 0.66rem; padding: 0 0.28rem; background: var(--day-badge-bg); color: var(--day-badge-text); }
        .calendar-actions { margin-top: 0.6rem; text-align: center; }
        .calendar-legend { margin-top: 0.45rem; display: flex; justify-content: center; gap: 0.6rem; flex-wrap: wrap; font-size: 0.78rem; color: var(--calendar-legend-text); }
        .legend-chip { display: inline-flex; align-items: center; gap: 0.32rem; }
        .legend-dot { width: 0.72rem; height: 0.72rem; border-radius: 999px; border: 1px solid var(--legend-dot-border); display: inline-block; }
        .legend-dot.finished { background: var(--legend-dot-finished-bg); }
        .legend-dot.pending { background: var(--legend-dot-pending-bg); }
        .modal-overlay { position: fixed; inset: 0; background: var(--modal-overlay-bg); display: flex; align-items: center; justify-content: center; padding: 1rem; z-index: 1200; }
        .modal-overlay[hidden] { display: none; }
        .modal-card { width: min(520px, 100%); background: var(--modal-card-bg); border: 1px solid var(--modal-card-border); border-radius: 10px; box-shadow: var(--modal-card-shadow); padding: 0.9rem; }
        .modal-card h3 { margin: 0 0 0.35rem; }
        .modal-card p { margin: 0.3rem 0 0.7rem; }
        .modal-option-list { display: grid; gap: 0.45rem; margin: 0 0 0.8rem; }
        .modal-option { display: flex; align-items: center; gap: 0.45rem; }
        .modal-bulk-actions { display: flex; gap: 0.45rem; margin: 0 0 0.65rem; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 0.45rem; }
        .modal-error { min-height: 1.2rem; margin: 0 0 0.55rem; }
    </style>
</head>
<body data-theme="<?php echo htmlspecialchars($interfaceTheme, ENT_QUOTES, 'UTF-8'); ?>">
    <main class="layout">
        <section class="panel">
            <header class="page-header">
                <h1><?php echo htmlspecialchars($headingPrefix . 'Journal Entry', ENT_QUOTES, 'UTF-8'); ?></h1>
                <div class="header-links">
                    <a class="pill" href="/index.php">Back to Dashboard</a>
                    <span class="pill">Theme: <?php echo htmlspecialchars(ucfirst($interfaceTheme), ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="pill">rJournaler_Web: v<?php echo htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="mode-toggle-row" style="display:flex;justify-content:flex-end;align-items:center;margin-bottom:1rem;">
                    <button id="mode-toggle-pill" type="button" class="theme-pill-mode-toggle">Mode: Desktop</button>
                    <style>
                    .theme-pill-mode-toggle {
                        border: 1.5px solid var(--control-btn-border);
                        background: var(--control-btn-bg);
                        color: var(--control-btn-text);
                        border-radius: 999px;
                        padding: 0.4rem 1.2rem;
                        font-weight: bold;
                        font-size: 1rem;
                        box-shadow: 0 1px 4px rgba(0,0,0,0.08);
                        cursor: pointer;
                        transition: background 0.15s, color 0.15s, border 0.15s;
                    }
                    .theme-pill-mode-toggle:hover, .theme-pill-mode-toggle:focus {
                        background: var(--control-btn-border);
                        color: var(--control-btn-bg);
                        outline: none;
                    }
                    </style>
                </div>
            </header>

            <?php if ($error !== null): ?>
                <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>

            <input type="hidden" id="csrf-token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" id="entry-uid" value="<?php echo htmlspecialchars((string) ($entry['entry_uid'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="entry-header-row">
                <div class="field-group">
                    <label for="entry-date">Entry Date</label>
                    <input id="entry-date" type="date" value="<?php echo htmlspecialchars((string) ($entry['entry_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="field-group">
                    <label for="entry-location-select">Entry Location</label>
                    <select id="entry-location-select">
                        <?php foreach ($entryWeatherOptions as $locationKey => $location): ?>
                            <?php $isSelectedLocation = (string) ($entry['weather_location_key'] ?? '') === (string) $locationKey; ?>
                            <option value="<?php echo htmlspecialchars((string) $locationKey, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $isSelectedLocation ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string) ($location['label'] ?? $locationKey), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label for="entry-title">Title</label>
                    <input id="entry-title" type="text" maxlength="255" value="<?php echo htmlspecialchars((string) ($entry['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>

            <div id="editor-block" class="editor-block">
                <label for="entry-content-editor">Content</label>
                <div class="editor-shell">
                <div class="editor-toolbar" id="editor-toolbar"></div>
                <div id="entry-content-editor" class="editor-surface" contenteditable="true" aria-label="Entry content editor"></div>
            </div>
            <div class="editor-stats-row" aria-live="polite">
                <span class="editor-stats-wordcount"><span id="word-count-label">Word count:</span> <span id="word-count">0</span></span>
                <span class="editor-stats-goal"><span id="word-goal-label">Daily Goal:</span> <span id="word-goal-status" class="goal-status pending">500 words to go!</span></span>
                <span class="editor-stats-readtime"><span id="read-time-label">Estimated read time:</span> <span id="read-time">0</span> min</span>
            </div>
            </div>
            <textarea id="entry-content" hidden><?php echo htmlspecialchars((string) ($entry['content_raw'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>

            <details id="meta-groups-fold" class="panel meta-fold" <?php echo strtoupper((string) ($entry['workflow_stage'] ?? '')) === 'COMPLETE' ? '' : 'hidden'; ?>>
                <summary class="meta-fold-summary">Processed Metadata</summary>
                <section id="meta-groups-panel" class="meta-shell">
                <p class="muted" style="margin:0 0 0.5rem;">Available only for entries in <strong>COMPLETE</strong> stage.</p>
                <div class="meta-tabs" role="tablist" aria-label="Metadata groups">
                    <button class="meta-tab active" type="button" role="tab" aria-selected="true" data-tab="group0">Meta Group 0</button>
                    <button class="meta-tab" type="button" role="tab" aria-selected="false" data-tab="group1">Meta Group 1</button>
                    <button class="meta-tab" type="button" role="tab" aria-selected="false" data-tab="group2">Meta Group 2</button>
                    <button class="meta-tab" type="button" role="tab" aria-selected="false" data-tab="group3">Meta Group 3</button>
                </div>

                <section class="meta-pane active" data-pane="group0">
                    
                    <?php if (is_array($metaGroups['group0'])): ?>
                        <dl class="meta-grid">
                            <div class="meta-row"><dt>Entry Title</dt><dd><?php echo htmlspecialchars((string) ($metaGroups['group0']['entry_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <div class="meta-row"><dt>Created</dt><dd><?php echo htmlspecialchars(formatLocalDateTime((string) ($metaGroups['group0']['created_datetime'] ?? ''), $userTimeZone), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <div class="meta-row"><dt>Modified</dt><dd><?php echo htmlspecialchars(formatLocalDateTime((string) ($metaGroups['group0']['modified_datetime'] ?? ''), $userTimeZone), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <div class="meta-row"><dt>Content Hash</dt><dd><code><?php echo htmlspecialchars((string) ($metaGroups['group0']['entry_hash_sha256'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></dd></div>
                            <div class="meta-row"><dt>Entry UID</dt><dd><code><?php echo htmlspecialchars((string) ($entry['entry_uid'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></dd></div>
                        </dl>
                    <?php else: ?>
                        <p class="muted">Meta Group 0 data is not available yet.</p>
                    <?php endif; ?>
                </section>

                <section class="meta-pane" data-pane="group1">
                    <h3 class="muted" style="margin-top:0;">Basic Entry Metadata Calculated By the Python TextStat Library</h3>
                    <?php if (is_array($metaGroups['group1'])): ?>
                        <div class="meta-table-wrap">
                            <table class="meta-table">
                                <thead>
                                    <tr>
                                        <th>Metric</th>
                                        <th>Value</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Word Count</td>
                                        <td><?php echo htmlspecialchars((string) ($metaGroups['group1']['word_count'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="meta-note">Total words in entry content.</td>
                                    </tr>
                                    <tr>
                                        <td>Read Time (min)</td>
                                        <td><?php
                                            $readMinutesValue = $metaGroups['group1']['reading_time_minutes'] ?? null;
                                            $readMinutesText = is_numeric($readMinutesValue)
                                                ? number_format((float) $readMinutesValue, 1, '.', '')
                                                : (string) $readMinutesValue;
                                            echo htmlspecialchars($readMinutesText, ENT_QUOTES, 'UTF-8');
                                        ?></td>
                                        <td class="meta-note">Estimated at ~200 words per minute.</td>
                                    </tr>
                                    <tr>
                                        <td>Flesch Reading Ease</td>
                                        <td><?php echo htmlspecialchars((string) ($metaGroups['group1']['flesch_reading_ease'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="meta-note">Estimates the readability of the text, with higher scores indicating easier reading (1-100).</td>
                                    </tr>
                                    <tr>
                                        <td>Flesch-Kincaid Grade Level</td>
                                        <td><?php echo htmlspecialchars((string) ($metaGroups['group1']['flesch_kincaid_grade'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="meta-note">Estimates the U.S. school grade required to understand the text.</td>
                                    </tr>
                                    <tr>
                                        <td>Gunning Fog</td>
                                        <td><?php echo htmlspecialchars((string) ($metaGroups['group1']['gunning_fog'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="meta-note">Gunning Fog index - Estimates the years of formal education needed to understand the text on first reading.</td>
                                    </tr>
                                    <tr>
                                        <td>SMOG Index</td>
                                        <td><?php echo htmlspecialchars((string) ($metaGroups['group1']['smog_index'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="meta-note">Simple Measure of Gobbledygook - Estimates the years of education needed to understand a piece of writing based on sentence length and complex words.</td>
                                    </tr>
                                    <tr>
                                        <td>ARI</td>
                                        <td><?php echo htmlspecialchars((string) ($metaGroups['group1']['automated_readability_index'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="meta-note">Automated Readability Index - Estimates the U.S. grade level needed to comprehend the text.</td>
                                    </tr>
                                    <tr>
                                        <td>Dale-Chall</td>
                                        <td><?php echo htmlspecialchars((string) ($metaGroups['group1']['dale_chall'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="meta-note">Dale-Chall readability score - Estimates the U.S. grade level required to understand the text based on familiar words.</td>
                                    </tr>
                                    <tr>
                                        <td>Avg Word Length</td>
                                        <td><?php echo htmlspecialchars((string) ($metaGroups['group1']['average_word_length'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="meta-note">Average characters per word.</td>
                                    </tr>
                                    <tr>
                                        <td>Long Word Ratio</td>
                                        <td><?php echo htmlspecialchars((string) ($metaGroups['group1']['long_word_ratio'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="meta-note">Share of words with 7+ characters.</td>
                                    </tr>
                                    <tr>
                                        <td>Thought Fragmentation</td>
                                        <td><?php echo htmlspecialchars((string) ($metaGroups['group1']['thought_fragmentation'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="meta-note">Measures the average number of sentences per 100 words, indicating the level of thought fragmentation.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="muted">Meta Group 1 data is not available yet.</p>
                    <?php endif; ?>
                </section>

                <section class="meta-pane" data-pane="group2">
                    <h3 class="muted" style="margin-top:0;">Deeper Analysis Performed By A Large Language Model (LLM)</h3>
                    <?php if (is_array($metaGroups['group2'])): ?>
                        <?php
                            $analysis = is_array($metaGroups['group2']['analysis_json'] ?? null)
                                ? $metaGroups['group2']['analysis_json']
                                : [];
                            $sentiment = is_array($analysis['sentiment_analysis'] ?? null) ? $analysis['sentiment_analysis'] : [];
                            $psych = is_array($analysis['psycholinguistics'] ?? null) ? $analysis['psycholinguistics'] : [];
                            $topics = is_array($analysis['topic_modeling'] ?? null) ? $analysis['topic_modeling'] : [];
                            $entities = is_array($analysis['named_entity_recognition'] ?? null) ? $analysis['named_entity_recognition'] : [];
                            $personality = is_array($analysis['personality_profile'] ?? null) ? $analysis['personality_profile'] : [];
                            $emotion = is_array($psych['emotional_state'] ?? null) ? $psych['emotional_state'] : [];
                        ?>

                        <section class="meta-section">
                            <dl class="meta-grid">
                                <div class="meta-row"><dt>LLM Model</dt><dd><?php echo htmlspecialchars((string) ($metaGroups['group2']['llm_model'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                <div class="meta-row"><dt>Summary</dt><dd><?php echo htmlspecialchars((string) ($analysis['text_summary'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            </dl>
                        </section>

                        <section class="meta-section">
                            <h3 class="meta-subtitle">Sentiment</h3>
                            <dl class="meta-grid">
                                <div class="meta-row"><dt>Label</dt><dd><?php echo htmlspecialchars((string) ($sentiment['label'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                <div class="meta-row"><dt>Score</dt><dd><?php echo htmlspecialchars((string) ($sentiment['score'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?> / 5</dd></div>
                                <div class="meta-row"><dt>Total Count</dt><dd><?php echo htmlspecialchars((string) ($sentiment['breakdown']['Total_Sentiment_Count'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            </dl>
                        </section>

                        <section class="meta-section">
                            <h3 class="meta-subtitle">Psycholinguistics</h3>
                            <dl class="meta-grid">
                                <div class="meta-row"><dt>Dominant Tense</dt><dd><?php echo htmlspecialchars((string) ($psych['tense']['dominant'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                <div class="meta-row"><dt>Emotional State</dt><dd><?php echo htmlspecialchars((string) ($emotion['dominant'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                <div class="meta-row"><dt>Generalization Terms</dt><dd><?php echo htmlspecialchars(renderListValues($psych['generalization']['terms'] ?? []), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            </dl>
                        </section>

                        <section class="meta-section">
                            <h3 class="meta-subtitle">Topics</h3>
                            <?php $exploratoryTopics = is_array($topics['general_exploratory'] ?? null) ? $topics['general_exploratory'] : []; ?>
                            <?php $latentTopics = is_array($topics['latent_structure_discovery'] ?? null) ? $topics['latent_structure_discovery'] : []; ?>
                            <div class="meta-row" style="margin-bottom:0.35rem;"><dt>Exploratory</dt><dd>
                                <div class="meta-tags">
                                    <?php if (count($exploratoryTopics) === 0): ?>
                                        <span class="muted">n/a</span>
                                    <?php else: ?>
                                        <?php foreach ($exploratoryTopics as $topic): ?>
                                            <span class="meta-tag"><?php echo htmlspecialchars((string) $topic, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </dd></div>
                            <div class="meta-row"><dt>Latent</dt><dd>
                                <div class="meta-tags">
                                    <?php if (count($latentTopics) === 0): ?>
                                        <span class="muted">n/a</span>
                                    <?php else: ?>
                                        <?php foreach ($latentTopics as $topic): ?>
                                            <span class="meta-tag"><?php echo htmlspecialchars((string) $topic, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </dd></div>
                        </section>

                        <section class="meta-section">
                            <h3 class="meta-subtitle">Named Entities</h3>
                            <dl class="meta-grid">
                                <div class="meta-row"><dt>People</dt><dd><?php echo htmlspecialchars(renderListValues($entities['persons'] ?? []), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                <div class="meta-row"><dt>Organizations</dt><dd><?php echo htmlspecialchars(renderListValues($entities['organizations'] ?? []), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                <div class="meta-row"><dt>Locations</dt><dd><?php echo htmlspecialchars(renderListValues($entities['locations'] ?? []), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                <div class="meta-row"><dt>Geo-Political</dt><dd><?php echo htmlspecialchars(renderListValues($entities['geopolitical_entities'] ?? []), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                <div class="meta-row"><dt>Dates/Times</dt><dd><?php echo htmlspecialchars(renderListValues($entities['dates_times'] ?? []), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            </dl>
                        </section>

                        <section class="meta-section">
                            <h3 class="meta-subtitle">Personality Profile</h3>
                            <dl class="meta-grid">
                                <div class="meta-row"><dt>Honesty-Humility</dt><dd><?php echo htmlspecialchars((string) ($personality['honesty_humility'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                <div class="meta-row"><dt>Emotionality</dt><dd><?php echo htmlspecialchars((string) ($personality['emotionality'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                <div class="meta-row"><dt>Extraversion</dt><dd><?php echo htmlspecialchars((string) ($personality['extraversion'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                <div class="meta-row"><dt>Agreeableness</dt><dd><?php echo htmlspecialchars((string) ($personality['agreeableness'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                <div class="meta-row"><dt>Conscientiousness</dt><dd><?php echo htmlspecialchars((string) ($personality['conscientiousness'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                <div class="meta-row"><dt>Openness</dt><dd><?php echo htmlspecialchars((string) ($personality['openness_to_experience'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                <div class="meta-row"><dt>Profile Summary</dt><dd><?php echo htmlspecialchars((string) ($personality['summary'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            </dl>
                        </section>
                    <?php else: ?>
                        <p class="muted">Meta Group 2 data is not available yet.</p>
                    <?php endif; ?>
                </section>

                <section class="meta-pane" data-pane="group3">
                    <h3 class="muted" style="margin-top:0;">NOAA weather snapshot for this entry's selected location.</h3>
                    <?php if (is_array($metaGroups['group3'])): ?>
                        <dl class="meta-grid">
                            <div class="meta-row"><dt>Location</dt><dd><?php echo htmlspecialchars((string) ($metaGroups['group3']['location_label'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <div class="meta-row"><dt>Observed</dt><dd><?php echo htmlspecialchars((string) ($metaGroups['group3']['observed_at'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <div class="meta-row"><dt>Summary</dt><dd><?php echo htmlspecialchars((string) ($metaGroups['group3']['current_summary'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <div class="meta-row"><dt>Temp (F)</dt><dd><?php echo htmlspecialchars((string) ($metaGroups['group3']['current_temperature_f'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <div class="meta-row"><dt>Feels Like (F)</dt><dd><?php echo htmlspecialchars((string) ($metaGroups['group3']['current_feels_like_f'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <div class="meta-row"><dt>Humidity (%)</dt><dd><?php echo htmlspecialchars((string) ($metaGroups['group3']['current_humidity_percent'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <div class="meta-row"><dt>Wind (mph)</dt><dd><?php echo htmlspecialchars((string) ($metaGroups['group3']['current_wind_speed_mph'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <div class="meta-row"><dt>Forecast</dt><dd><?php echo htmlspecialchars((string) ($metaGroups['group3']['forecast_short'] ?? 'n/a'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                            <div class="meta-row"><dt>Map</dt><dd>
                                <?php if (trim((string) ($metaGroups['group3']['map_url'] ?? '')) !== ''): ?>
                                    <a href="<?php echo htmlspecialchars((string) ($metaGroups['group3']['map_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Open NOAA map</a>
                                <?php else: ?>
                                    n/a
                                <?php endif; ?>
                            </dd></div>
                        </dl>
                    <?php else: ?>
                        <p class="muted">Meta Group 3 weather data is not available yet.</p>
                    <?php endif; ?>
                </section>
                </section>
            </details>

            <div style="display:flex;gap:0.6rem;align-items:center;">
                <p id="save-status" class="muted" style="margin:0;"></p>
                <button id="flush-pending-button" type="button" hidden style="border-radius:999px;padding:0.25rem 0.5rem;font-size:0.9rem;">Flush pending (0)</button>
            </div>
        </section>

        <aside class="sidebar">
            <section class="panel">
                <h2>Entry Controls</h2>
                <p class="stat-line">Current stage: <strong id="workflow-stage"><?php echo htmlspecialchars((string) ($entry['workflow_stage'] ?? 'AUTOSAVE'), ENT_QUOTES, 'UTF-8'); ?></strong></p>

                <div class="action-stack">
                    <button id="save-button" type="button">Save Entry</button>
                    <button id="finish-button" type="button">Finish (Lock + Process)</button>
                    <button id="reprocess-button" type="button">Reprocess</button>
                    <button id="unlock-button" type="button">Unlock (Set to Written)</button>
                    <button id="finalize-button" type="button">Mark Final</button>
                    <button id="delete-button" type="button">Delete Entry</button>
                </div>
                <p class="muted" style="margin:0.6rem 0 0;">
                    <a id="timeline-link" href="/dashboards/analysis-timeline.php">Open timeline around this entry</a>
                </p>
            </section>

            <section class="panel">
                <h2>Entry Calendar</h2>
                <form method="get" action="/entry.php" class="calendar-controls">
                    <?php if ((string) ($entry['entry_uid'] ?? '') !== ''): ?>
                        <input type="hidden" name="uid" value="<?php echo htmlspecialchars((string) ($entry['entry_uid'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <label>
                        Year
                        <select name="calendar_year" onchange="this.form.submit()">
                            <?php for ($year = 1970; $year <= 2100; $year++): ?>
                                <option value="<?php echo $year; ?>" <?php echo $year === $calendarYear ? 'selected' : ''; ?>><?php echo $year; ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                    <label>
                        Month
                        <select name="calendar_month" onchange="this.form.submit()">
                            <?php for ($month = 1; $month <= 12; $month++): ?>
                                <option value="<?php echo $month; ?>" <?php echo $month === $calendarMonth ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) DateTimeImmutable::createFromFormat('!m', (string) $month)->format('F'), ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                </form>

                <?php
                    $calendarFirst = DateTimeImmutable::createFromFormat('Y-n-j', $calendarYear . '-' . $calendarMonth . '-1');
                    if (!$calendarFirst instanceof DateTimeImmutable) {
                        $calendarFirst = new DateTimeImmutable('first day of this month');
                    }
                    $daysInMonth = (int) $calendarFirst->format('t');
                    $startWeekday = (int) $calendarFirst->format('w'); // 0=Sun
                    $cells = array_fill(0, $startWeekday, null);
                    for ($day = 1; $day <= $daysInMonth; $day++) {
                        $cells[] = $day;
                    }
                    while (count($cells) % 7 !== 0) {
                        $cells[] = null;
                    }
                    $weekLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                ?>

                <table class="calendar-grid" aria-label="Entry calendar">
                    <thead>
                        <tr>
                            <?php foreach ($weekLabels as $weekLabel): ?>
                                <th><?php echo htmlspecialchars($weekLabel, ENT_QUOTES, 'UTF-8'); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($i = 0; $i < count($cells); $i += 7): ?>
                            <tr>
                                <?php for ($j = 0; $j < 7; $j++): ?>
                                    <?php $dayValue = $cells[$i + $j]; ?>
                                    <?php if (!is_int($dayValue)): ?>
                                        <td class="empty"></td>
                                    <?php else: ?>
                                        <?php
                                            $dateKey = sprintf('%04d-%02d-%02d', $calendarYear, $calendarMonth, $dayValue);
                                            $entryForDay = $calendarEntriesByDate[$dateKey] ?? null;
                                        ?>
                                        <?php if (is_array($entryForDay)): ?>
                                            <?php $isSelectedDay = $dateKey === (string) ($entry['entry_date'] ?? ''); ?>
                                            <?php $dayClass = ((bool) ($entryForDay['is_finished'] ?? false)) ? 'has-entry' : 'has-entry-pending'; ?>
                                            <td class="<?php echo $dayClass . ($isSelectedDay ? ' selected-day' : ''); ?>" title="<?php echo htmlspecialchars((string) ($entryForDay['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php
                                                    $totalWords = (int) ($entryForDay['total_words'] ?? 0);
                                                ?>
                                                <div class="day-wrap">
                                                    <a href="/entry.php?uid=<?php echo urlencode((string) ($entryForDay['entry_uid'] ?? '')); ?>&calendar_year=<?php echo $calendarYear; ?>&calendar_month=<?php echo $calendarMonth; ?>"><?php echo $dayValue; ?></a>
                                                    <div class="day-badges">
                                                        <span class="day-badge" title="Total words for date: <?php echo $totalWords; ?>">
                                                            <?php echo $totalWords; ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>
                                        <?php else: ?>
                                            <td><?php echo $dayValue; ?></td>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endfor; ?>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
                <div class="calendar-legend" aria-label="Calendar legend">
                    <span class="legend-chip"><span class="legend-dot finished" aria-hidden="true"></span>Finished</span>
                    <span class="legend-chip"><span class="legend-dot pending" aria-hidden="true"></span>Not Finished</span>
                </div>
                <div class="calendar-actions">
                    <?php if ($todayEntryUid !== ''): ?>
                        <a href="/entry.php?uid=<?php echo urlencode($todayEntryUid); ?>&calendar_year=<?php echo (int) $userNow->format('Y'); ?>&calendar_month=<?php echo (int) $userNow->format('n'); ?>">Today</a>
                    <?php else: ?>
                        <a href="/entry.php?calendar_year=<?php echo (int) $userNow->format('Y'); ?>&calendar_month=<?php echo (int) $userNow->format('n'); ?>">Today</a>
                    <?php endif; ?>
                </div>
            </section>
        </aside>
    </main>

    <div id="reprocess-modal" class="modal-overlay" hidden>
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="reprocess-modal-title">
            <h3 id="reprocess-modal-title">Select Meta Groups To Reprocess</h3>
            <p class="muted">All groups are selected by default. Choose one or more groups before continuing.</p>
            <form id="reprocess-modal-form">
                <div class="modal-option-list">
                    <label class="modal-option"><input type="checkbox" name="pipeline_stage_ids" value="meta_group_0" checked> Meta Group 0</label>
                    <label class="modal-option"><input type="checkbox" name="pipeline_stage_ids" value="meta_group_1" checked> Meta Group 1</label>
                    <label class="modal-option"><input type="checkbox" name="pipeline_stage_ids" value="meta_group_2_llm" checked> Meta Group 2 (LLM)</label>
                    <label class="modal-option"><input type="checkbox" name="pipeline_stage_ids" value="meta_group_3_weather" checked> Meta Group 3 (Weather)</label>
                    <label class="modal-option"><input type="checkbox" name="pipeline_stage_ids" value="metrics_finalize" checked> Metrics Finalize</label>
                </div>
                <div class="modal-bulk-actions">
                    <button id="reprocess-modal-select-all" type="button">Select all</button>
                    <button id="reprocess-modal-clear-all" type="button">Clear all</button>
                </div>
                <p id="reprocess-modal-error" class="error modal-error" aria-live="polite"></p>
                <div class="modal-actions">
                    <button id="reprocess-modal-cancel" type="button">Cancel</button>
                    <button id="reprocess-modal-confirm" type="submit">Queue Reprocess</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const initialEntry = <?php echo $entryJson; ?>;
        const editorToolbarOptions = <?php echo $editorToolbarOptionsJson; ?>;
        const editorToolbarButtons = <?php echo $editorToolbarButtonsJson; ?>;
        const textarea = document.getElementById('entry-content');
        const titleInput = document.getElementById('entry-title');
        const dateInput = document.getElementById('entry-date');
        const entryLocationSelect = document.getElementById('entry-location-select');
        const saveButton = document.getElementById('save-button');
        const entryUidInput = document.getElementById('entry-uid');
        const csrfTokenInput = document.getElementById('csrf-token');
        const wordCount = document.getElementById('word-count');
        const wordCountLabel = document.getElementById('word-count-label');
        const wordGoalStatus = document.getElementById('word-goal-status');
        const wordGoalLabel = document.getElementById('word-goal-label');
        const readTime = document.getElementById('read-time');
        const readTimeLabel = document.getElementById('read-time-label');
        const editorBlock = document.getElementById('editor-block');
        const editorShell = editorBlock ? editorBlock.querySelector('.editor-shell') : null;
        const editorStatsRow = editorBlock ? editorBlock.querySelector('.editor-stats-row') : null;
        const editorSurface = document.getElementById('entry-content-editor');
        const editorToolbar = document.getElementById('editor-toolbar');
        // Dynamically render toolbar buttons based on user settings
        function renderEditorToolbar() {
            if (!editorToolbar) return;
            editorToolbar.innerHTML = '';
            const btns = Array.isArray(editorToolbarButtons) ? editorToolbarButtons : [];
            const opts = editorToolbarOptions || {};
            // Always show Full Screen and Time buttons at the end
            btns.forEach((key) => {
                switch (key) {
                    case 'bold':
                        editorToolbar.appendChild(createToolbarButton('bold', '<strong>B</strong>', {cmd: 'bold'}));
                        break;
                    case 'italic':
                        editorToolbar.appendChild(createToolbarButton('italic', '<em>I</em>', {cmd: 'italic'}));
                        break;
                    case 'underline':
                        editorToolbar.appendChild(createToolbarButton('underline', '<u>U</u>', {cmd: 'underline'}));
                        break;
                    case 'strikeThrough':
                        editorToolbar.appendChild(createToolbarButton('strikeThrough', '<s>S</s>', {cmd: 'strikeThrough'}));
                        break;
                    case 'heading':
                        // Add heading buttons and select
                        ['h1','h2','h3','h4','h5'].forEach(h => {
                            editorToolbar.appendChild(createToolbarButton('heading-'+h, h.toUpperCase(), {block: h, class: 'toolbar-heading-button'}));
                        });
                        // Heading select
                        const select = document.createElement('select');
                        select.id = 'editor-heading-select';
                        select.className = 'toolbar-heading-select';
                        select.setAttribute('aria-label', 'Choose heading level');
                        select.innerHTML = '<option value="">Head</option>' + ['h1','h2','h3','h4','h5'].map(h => `<option value="${h}">${h.toUpperCase()}</option>`).join('');
                        editorToolbar.appendChild(select);
                        break;
                    case 'ul':
                        editorToolbar.appendChild(createToolbarButton('ul', 'UL', {cmd: 'insertUnorderedList'}));
                        break;
                    case 'ol':
                        editorToolbar.appendChild(createToolbarButton('ol', 'OL', {cmd: 'insertOrderedList'}));
                        break;
                }
            });
            // Save status (mobile)
            const saveStatusMobile = document.createElement('span');
            saveStatusMobile.id = 'save-status-mobile';
            saveStatusMobile.className = 'toolbar-status';
            saveStatusMobile.setAttribute('aria-live', 'polite');
            editorToolbar.appendChild(saveStatusMobile);
            // Full Screen button (always shown)
            const fsBtn = createToolbarButton('fullscreen', 'FS', {custom: 'toggle-mobile-fullscreen', class: 'toolbar-mobile-only', title: 'Toggle fullscreen editor'});
            editorToolbar.appendChild(fsBtn);
            // Time button (always shown)
            const timeBtn = createToolbarButton('time', '&#128339;', {custom: 'insert-time-heading', class: 'toolbar-time-button', title: 'Insert current time'});
            editorToolbar.appendChild(timeBtn);
        }

        function createToolbarButton(key, label, opts) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.innerHTML = label;
            btn.setAttribute('data-editor-action', '');
            if (opts && opts.cmd) btn.setAttribute('data-cmd', opts.cmd);
            if (opts && opts.block) btn.setAttribute('data-block', opts.block);
            if (opts && opts.custom) btn.setAttribute('data-custom', opts.custom);
            if (opts && opts.class) btn.className = opts.class;
            if (opts && opts.title) btn.title = opts.title;
            return btn;
        }

        renderEditorToolbar();
        // After rendering, re-query mobileFullscreenButton
        const editorHeadingSelect = document.getElementById('editor-heading-select');
        const mobileFullscreenButton = editorToolbar
            ? editorToolbar.querySelector('button[data-custom="toggle-mobile-fullscreen"]')
            : null;
        const modeStorageKey = 'entryPageMode';
        let currentMode = 'desktop';
        const modeTogglePill = document.getElementById('mode-toggle-pill');

        function getSavedMode() {
            try {
                const saved = String(window.localStorage.getItem(modeStorageKey) || '').toLowerCase();
                return saved === 'mobile' ? 'mobile' : 'desktop';
            } catch (_error) {
                return 'desktop';
            }
        }

        function saveMode(mode) {
            try {
                window.localStorage.setItem(modeStorageKey, mode);
            } catch (_error) {
                // Ignore storage failures and continue with in-memory mode.
            }
        }

        function syncModeClasses() {
            const mobileMode = currentMode === 'mobile';
            const portraitMode = mobileMode && window.matchMedia('(orientation: portrait)').matches;

            document.body.classList.toggle('mode-mobile', mobileMode);
            document.documentElement.classList.toggle('mode-mobile', mobileMode);
            document.body.classList.toggle('mode-mobile-portrait', portraitMode);
            document.documentElement.classList.toggle('mode-mobile-portrait', portraitMode);
        }

        function setMode(newMode) {
            currentMode = newMode === 'mobile' ? 'mobile' : 'desktop';
            saveMode(currentMode);
            syncModeClasses();

            if (!isMobileViewport() && document.body.classList.contains('editor-mobile-fullscreen')) {
                document.body.classList.remove('editor-mobile-fullscreen');
                document.documentElement.classList.remove('editor-mobile-fullscreen');
            }

            if (modeTogglePill) {
                modeTogglePill.textContent = 'Mode: ' + (currentMode.charAt(0).toUpperCase() + currentMode.slice(1));
                modeTogglePill.className = 'mode-pill mode-' + currentMode;
            }

            refreshStatsLabels();
            lockFullscreenViewportHeight();
            refreshMobileFullscreenButton();
            lockDesktopEditorHeight();
            computeStats(textarea.value);
            lastPortraitMobile = isPortraitMobileViewport();
        }

        if (modeTogglePill) {
            modeTogglePill.addEventListener('click', function() {
                setMode(currentMode === 'desktop' ? 'mobile' : 'desktop');
            });
        }

        const workflowStage = document.getElementById('workflow-stage');
        const saveStatus = document.getElementById('save-status');
        const saveStatusMobile = document.getElementById('save-status-mobile');
        const finishButton = document.getElementById('finish-button');
        const reprocessButton = document.getElementById('reprocess-button');
        const unlockButton = document.getElementById('unlock-button');
        const finalizeButton = document.getElementById('finalize-button');
        const deleteButton = document.getElementById('delete-button');
        const timelineLink = document.getElementById('timeline-link');
        const metaPanel = document.getElementById('meta-groups-panel');
        const metaFold = document.getElementById('meta-groups-fold');
        const metaFoldSummary = metaFold ? metaFold.querySelector('.meta-fold-summary') : null;
        const reprocessModal = document.getElementById('reprocess-modal');
        const reprocessModalForm = document.getElementById('reprocess-modal-form');
        const reprocessModalCancel = document.getElementById('reprocess-modal-cancel');
        const reprocessModalSelectAll = document.getElementById('reprocess-modal-select-all');
        const reprocessModalClearAll = document.getElementById('reprocess-modal-clear-all');
        const reprocessModalError = document.getElementById('reprocess-modal-error');
        const defaultEntryDate = <?php echo json_encode($defaultEntryDate, JSON_UNESCAPED_SLASHES); ?>;
        const defaultEntryTitle = <?php echo json_encode($defaultEntryTitle, JSON_UNESCAPED_SLASHES); ?>;
        const weatherLocationOptions = <?php echo $entryWeatherOptionsJson; ?>;
        const userTimeZone = <?php echo json_encode($userTimeZone, JSON_UNESCAPED_SLASHES); ?>;
        const reprocessStageDefaults = ['meta_group_0', 'meta_group_1', 'meta_group_2_llm', 'meta_group_3_weather', 'metrics_finalize'];
        let autosaveTimer = null;
        let saveInFlight = false;
        let currentStage = String((initialEntry && initialEntry.workflow_stage) || 'AUTOSAVE');
        let isBodyLocked = Number((initialEntry && initialEntry.body_locked) || 0) === 1;
        let lastPortraitMobile = false;

        function setSaveStatus(message, tone, mobileMessage) {
            if (saveStatus) {
                saveStatus.textContent = String(message || '');
                saveStatus.className = String(tone || 'muted');
            }
            if (saveStatusMobile) {
                saveStatusMobile.textContent = String(mobileMessage || '');
                saveStatusMobile.className = 'toolbar-status ' + String(tone || 'muted');
            }
        }

        function openReprocessModal() {
            if (!reprocessModal || !reprocessModalForm) {
                return Promise.resolve(reprocessStageDefaults.slice());
            }

            const checkboxes = reprocessModalForm.querySelectorAll('input[name="pipeline_stage_ids"]');
            checkboxes.forEach((box) => {
                if (box instanceof HTMLInputElement) {
                    box.checked = true;
                }
            });
            if (reprocessModalError) {
                reprocessModalError.textContent = '';
            }
            reprocessModal.hidden = false;

            const first = checkboxes[0];
            if (first instanceof HTMLInputElement) {
                first.focus();
            }

            return new Promise((resolve) => {
                const close = (selection) => {
                    reprocessModal.hidden = true;
                    reprocessModal.removeEventListener('click', onOverlayClick);
                    document.removeEventListener('keydown', onKeydown);
                    reprocessModalForm.removeEventListener('submit', onSubmit);
                    reprocessModalCancel.removeEventListener('click', onCancel);
                    resolve(selection);
                };

                const onCancel = () => close(null);
                const onOverlayClick = (event) => {
                    if (event.target === reprocessModal) {
                        close(null);
                    }
                };
                const onKeydown = (event) => {
                    if (event.key === 'Escape') {
                        close(null);
                    }
                };
                const onSubmit = (event) => {
                    event.preventDefault();
                    const selected = Array.from(reprocessModalForm.querySelectorAll('input[name="pipeline_stage_ids"]:checked'))
                        .map((input) => String(input.value || '').trim())
                        .filter((value) => value !== '');
                    if (selected.length === 0) {
                        if (reprocessModalError) {
                            reprocessModalError.textContent = 'Select at least one meta group.';
                        }
                        return;
                    }
                    close(selected);
                };

                reprocessModal.addEventListener('click', onOverlayClick);
                document.addEventListener('keydown', onKeydown);
                reprocessModalForm.addEventListener('submit', onSubmit);
                reprocessModalCancel.addEventListener('click', onCancel);
            });
        }

        function setAllReprocessStageSelections(checked) {
            if (!reprocessModalForm) {
                return;
            }
            const checkboxes = reprocessModalForm.querySelectorAll('input[name="pipeline_stage_ids"]');
            checkboxes.forEach((box) => {
                if (box instanceof HTMLInputElement) {
                    box.checked = checked;
                }
            });
            if (reprocessModalError) {
                reprocessModalError.textContent = '';
            }
        }

        function computeStats(text) {
            const words = text.trim() === '' ? [] : text.trim().split(/\s+/);
            const count = words.length;
            const minutes = count === 0 ? 0 : Math.max(0.1, Math.round((count / 200) * 10) / 10);
            const remaining = Math.max(0, 500 - count);
            const portraitMobile = isPortraitMobileViewport();
            if (count >= 500) {
                updateWordStats(count, portraitMobile ? 'Met' : 'Daily Goal Met!', minutes, true);
            } else {
                updateWordStats(count, portraitMobile ? (String(remaining) + ' More') : (String(remaining) + ' words to go!'), minutes, false);
            }
        }

        function updateWordStats(count, goalStatus, readMinutes, goalMet) {
            if (wordCount) wordCount.textContent = String(count);
            if (wordGoalStatus) {
                wordGoalStatus.textContent = goalStatus;
                wordGoalStatus.classList.toggle('met', goalMet === true);
                wordGoalStatus.classList.toggle('pending', goalMet !== true);
            }
            if (readTime) readTime.textContent = String(readMinutes);
        }

        function isMobileViewport() {
            return currentMode === 'mobile';
        }

        function isPortraitMobileViewport() {
            if (currentMode === 'mobile') {
                // Optionally, check orientation for portrait mode
                return window.matchMedia('(orientation: portrait)').matches;
            }
            return false;
        }

        function refreshStatsLabels() {
            const portraitMobile = isPortraitMobileViewport();
            if (wordCountLabel) {
                wordCountLabel.textContent = isMobileViewport() ? (portraitMobile ? 'WC:' : 'Word count:') : 'Word count:';
            }
            if (wordGoalLabel) {
                wordGoalLabel.textContent = isMobileViewport() ? (portraitMobile ? 'Goal:' : 'Daily Goal:') : 'Daily Goal:';
            }
            if (readTimeLabel) {
                readTimeLabel.textContent = isMobileViewport() ? (portraitMobile ? 'Read Time:' : 'Estimated read time:') : 'Estimated read time:';
            }
        }

        function refreshMobileFullscreenButton() {
            if (!(mobileFullscreenButton instanceof HTMLButtonElement)) {
                return;
            }
            const isFullscreen = document.body.classList.contains('editor-mobile-fullscreen');
            mobileFullscreenButton.textContent = isFullscreen ? 'Exit' : 'FS';
            mobileFullscreenButton.title = isFullscreen ? 'Exit fullscreen editor' : 'Toggle fullscreen editor';
        }

        function toggleMobileFullscreen() {
            if (!editorBlock || !isMobileViewport()) {
                return;
            }
            document.body.classList.toggle('editor-mobile-fullscreen');
            if (document.documentElement) {
                document.documentElement.classList.toggle('editor-mobile-fullscreen', document.body.classList.contains('editor-mobile-fullscreen'));
            }
            lockFullscreenViewportHeight();
            refreshMobileFullscreenButton();
        }

        function lockFullscreenViewportHeight() {
            if (!document.documentElement) {
                return;
            }
            if (document.body.classList.contains('editor-mobile-fullscreen')) {
                const viewportHeight = window.visualViewport && Number.isFinite(window.visualViewport.height)
                    ? window.visualViewport.height
                    : window.innerHeight;
                document.documentElement.style.setProperty('--editor-mobile-fullscreen-height', String(Math.round(viewportHeight)) + 'px');
            } else {
                document.documentElement.style.removeProperty('--editor-mobile-fullscreen-height');
                document.documentElement.classList.remove('editor-mobile-fullscreen');
            }
        }

        function lockDesktopEditorHeight() {
            if (!(editorShell instanceof HTMLElement)) {
                return;
            }
            const desktopViewport = window.matchMedia('(min-width: 961px)').matches;
            if (!desktopViewport || isMobileViewport()) {
                document.documentElement.style.removeProperty('--desktop-editor-shell-height');
                return;
            }

            const shellTop = editorShell.getBoundingClientRect().top;
            const statsHeight = editorStatsRow instanceof HTMLElement
                ? editorStatsRow.getBoundingClientRect().height
                : 0;
            const metadataSummaryHeight = (metaFold instanceof HTMLElement
                && metaFoldSummary instanceof HTMLElement
                && !metaFold.hidden)
                ? metaFoldSummary.getBoundingClientRect().height
                : 0;
            const viewportPadding = 18;
            const available = Math.floor(window.innerHeight - shellTop - statsHeight - metadataSummaryHeight - viewportPadding);
            const shellHeight = Math.max(320, available);
            document.documentElement.style.setProperty('--desktop-editor-shell-height', String(shellHeight) + 'px');
        }

        function payload() {
            const selectedLocationKey = entryLocationSelect ? String(entryLocationSelect.value || '').trim() : '';
            const selectedLocation = selectedLocationKey !== ''
                ? weatherLocationOptions[selectedLocationKey]
                : null;
            return {
                _csrf: csrfTokenInput.value,
                entry_uid: entryUidInput.value || '',
                entry_date: dateInput.value,
                entry_location_key: selectedLocationKey,
                entry_location: selectedLocation,
                title: titleInput.value,
                content: textarea.value
            };
        }

        function titleFromEntryDate(dateValue) {
            const value = String(dateValue || '').trim();
            if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
                return '';
            }
            const parts = value.split('-');
            const year = Number(parts[0]);
            const month = Number(parts[1]);
            const day = Number(parts[2]);
            const dt = new Date(year, month - 1, day);
            if (Number.isNaN(dt.getTime())) {
                return '';
            }
            const weekday = dt.toLocaleDateString(undefined, { weekday: 'long' });
            return value + ' | ' + weekday;
        }

        function updateTimelineLink() {
            if (!timelineLink) {
                return;
            }
            const params = new URLSearchParams();
            const entryDateValue = String(dateInput.value || '').trim();
            const entryUidValue = String(entryUidInput.value || '').trim();
            if (entryDateValue !== '') {
                params.set('focus_date', entryDateValue);
            }
            if (entryUidValue !== '') {
                params.set('focus_uid', entryUidValue);
            }

            const hasFocus = params.toString() !== '';
            timelineLink.href = '/dashboards/analysis-timeline.php' + (hasFocus ? ('?' + params.toString()) : '');
            timelineLink.classList.toggle('link-disabled', !hasFocus);
            timelineLink.setAttribute('aria-disabled', hasFocus ? 'false' : 'true');
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function applyInlineMarkdown(text) {
            let output = escapeHtml(text);
            output = output.replace(/`([^`]+)`/g, '<code>$1</code>');
            output = output.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            output = output.replace(/__([^_]+)__/g, '<u>$1</u>');
            output = output.replace(/\*([^*]+)\*/g, '<em>$1</em>');
            output = output.replace(/~~([^~]+)~~/g, '<del>$1</del>');
            return output;
        }

        function renderMarkdownToHtml(text) {

            const source = String(text || '').replace(/\r\n?/g, '\n');
            if (source.trim() === '') {
                return '<p><br></p>';
            }

            const lines = source.split('\n');
            const html = [];
            let listType = null;

            const closeListIfNeeded = () => {
                if (listType) {
                    html.push(listType === 'ol' ? '</ol>' : '</ul>');
                    listType = null;
                }
            };

            for (const line of lines) {
                const headerMatch = /^(#{1,6})\s+(.+)$/.exec(line);
                const orderedMatch = /^\s*\d+\.\s+(.+)$/.exec(line);
                const bulletMatch = /^\s*[-*]\s+(.+)$/.exec(line);

                if (headerMatch) {
                    closeListIfNeeded();
                    const level = headerMatch[1].length;
                    html.push('<h' + level + '>' + applyInlineMarkdown(headerMatch[2]) + '</h' + level + '>');
                    continue;
                }

                if (orderedMatch) {
                    if (listType !== 'ol') {
                        closeListIfNeeded();
                        html.push('<ol>');
                        listType = 'ol';
                    }
                    html.push('<li>' + applyInlineMarkdown(orderedMatch[1]) + '</li>');
                    continue;
                }

                if (bulletMatch) {
                    if (listType !== 'ul') {
                        closeListIfNeeded();
                        html.push('<ul>');
                        listType = 'ul';
                    }
                    html.push('<li>' + applyInlineMarkdown(bulletMatch[1]) + '</li>');
                    continue;
                }

                closeListIfNeeded();
                if (line.trim() === '') {
                    html.push('<p><br></p>');
                } else {
                    html.push('<p>' + applyInlineMarkdown(line) + '</p>');
                }
            }

            closeListIfNeeded();
            return html.join('');
        }

        function markdownFromNode(node) {
            if (node.nodeType === Node.TEXT_NODE) {
                return String(node.nodeValue || '').replace(/\u00A0/g, ' ');
            }
            if (node.nodeType !== Node.ELEMENT_NODE) {
                return '';
            }

            const element = node;
            const tag = element.tagName.toLowerCase();
            const childText = Array.from(element.childNodes).map(markdownFromNode).join('');

            if (tag === 'br') {
                return '\n';
            }
            if (tag === 'strong' || tag === 'b') {
                return '**' + childText + '**';
            }
            if (tag === 'em' || tag === 'i') {
                return '*' + childText + '*';
            }
            if (tag === 'u') {
                return '__' + childText + '__';
            }
            if (tag === 'del' || tag === 's') {
                return '~~' + childText + '~~';
            }
            if (tag === 'code') {
                return '`' + childText.replace(/\n+/g, ' ') + '`';
            }
            if (tag === 'h1' || tag === 'h2' || tag === 'h3' || tag === 'h4' || tag === 'h5' || tag === 'h6') {
                const level = Number(tag.slice(1));
                return '#'.repeat(level) + ' ' + childText.trim() + '\n\n';
            }
            if (tag === 'p' || tag === 'div') {
                const trimmed = childText.trim();
                return trimmed === '' ? '\n' : trimmed + '\n\n';
            }
            if (tag === 'blockquote') {
                const lines = childText.trim().split('\n').filter((line) => line !== '');
                if (lines.length === 0) {
                    return '';
                }
                return lines.map((line) => '> ' + line).join('\n') + '\n\n';
            }
            if (tag === 'ul') {
                const items = Array.from(element.children)
                    .filter((child) => child.tagName.toLowerCase() === 'li')
                    .map((child) => '- ' + markdownFromNode(child).trim());
                return items.length === 0 ? '' : items.join('\n') + '\n\n';
            }
            if (tag === 'ol') {
                const items = Array.from(element.children)
                    .filter((child) => child.tagName.toLowerCase() === 'li')
                    .map((child, index) => String(index + 1) + '. ' + markdownFromNode(child).trim());
                return items.length === 0 ? '' : items.join('\n') + '\n\n';
            }
            if (tag === 'li') {
                return childText.trim();
            }

            return childText;
        }

        function syncMarkdownFromEditor() {
            if (!editorSurface) {
                return;
            }
            const raw = Array.from(editorSurface.childNodes).map(markdownFromNode).join('');
            textarea.value = raw.replace(/\n{3,}/g, '\n\n').trimEnd();
        }

        function syncEditorFromMarkdown() {
            if (!editorSurface) {
                return;
            }
            editorSurface.innerHTML = renderMarkdownToHtml(textarea.value);
        }

        function stripEdgeBlankLines(text) {
            const normalized = String(text || '').replace(/\r\n?/g, '\n');
            const lines = normalized.split('\n');
            while (lines.length > 0 && lines[0].trim() === '') {
                lines.shift();
            }
            while (lines.length > 0 && lines[lines.length - 1].trim() === '') {
                lines.pop();
            }
            return lines.join('\n');
        }

        function normalizeEditorContentForPersist() {
            syncMarkdownFromEditor();
            const normalized = stripEdgeBlankLines(textarea.value);
            if (normalized !== textarea.value) {
                textarea.value = normalized;
                syncEditorFromMarkdown();
            }
            computeStats(textarea.value);
        }

        function setToolbarEnabled(enabled) {
            if (!editorToolbar) {
                return;
            }
            const buttons = editorToolbar.querySelectorAll('button[data-editor-action]');
            buttons.forEach((button) => {
                button.disabled = !enabled;
            });
        }

        function refreshStageUi() {
            const upperStage = String(currentStage || '').toUpperCase();
            const lockHeaderFields = isBodyLocked || upperStage === 'COMPLETE' || upperStage === 'FINAL';
            workflowStage.textContent = currentStage;
            textarea.disabled = isBodyLocked;
            if (editorSurface) {
                editorSurface.setAttribute('contenteditable', isBodyLocked ? 'false' : 'true');
            }
            setToolbarEnabled(!isBodyLocked);
            titleInput.disabled = lockHeaderFields;
            dateInput.disabled = lockHeaderFields;
            if (entryLocationSelect) {
                entryLocationSelect.disabled = lockHeaderFields;
            }
            saveButton.disabled = lockHeaderFields;
            finishButton.disabled = entryUidInput.value === '' || isBodyLocked || upperStage === 'FINAL' || upperStage === 'IN_PROCESS';
            reprocessButton.disabled = entryUidInput.value === '' || upperStage === 'IN_PROCESS';
            unlockButton.disabled = entryUidInput.value === '' || !lockHeaderFields;
            finalizeButton.disabled = entryUidInput.value === '' || upperStage !== 'COMPLETE';
            deleteButton.disabled = entryUidInput.value === '' || !(upperStage === 'AUTOSAVE' || upperStage === 'WRITTEN');
            if (metaFold) {
                const showMetadata = upperStage === 'COMPLETE';
                metaFold.hidden = !showMetadata;
                if (!showMetadata) {
                    metaFold.open = false;
                }
            } else if (metaPanel) {
                metaPanel.hidden = upperStage !== 'COMPLETE';
            }
        }

        async function autosaveDraft() {
            if (saveInFlight) {
                return;
            }
            if (isBodyLocked) {
                return;
            }
            syncMarkdownFromEditor();
            setSaveStatus('Saving draft...', 'muted', 'Saving');
            try {
                const response = await fetch('/api/entry-autosave.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload())
                });

                if (!response.ok) {
                    throw new Error('Autosave failed');
                }

                const data = await response.json();
                if (data.ok && typeof data.entry_uid === 'string' && data.entry_uid !== '' && entryUidInput.value === '') {
                    entryUidInput.value = data.entry_uid;
                    history.replaceState({}, '', '/entry.php?uid=' + encodeURIComponent(data.entry_uid));
                    updateTimelineLink();
                }

                if (typeof data.stage === 'string' && data.stage !== '') {
                    currentStage = data.stage;
                }
                if (data.locked === true) {
                    isBodyLocked = true;
                }
                refreshStageUi();

                setSaveStatus('Draft autosaved', 'ok', 'Saved');
            } catch (err) {
                // Fallback: queue the autosave in IndexedDB so SW can sync it later.
                try {
                    const queued = payload();
                    queued._queued_at = new Date().toISOString();
                    await idbSavePending(queued);
                    setSaveStatus('Saved locally (offline)', 'muted', 'Saved locally');

                    // If service worker + background sync available, register a sync
                    if ('serviceWorker' in navigator && 'SyncManager' in window && navigator.serviceWorker.controller) {
                        try {
                            const reg = await navigator.serviceWorker.ready;
                            await reg.sync.register('autosave-sync');
                        } catch (_e) {
                            // ignore sync registration failures; we'll flush on online event
                        }
                    }
                } catch (_e) {
                    setSaveStatus('Autosave error', 'error', 'Error');
                }
            }
        }

        // IndexedDB helpers for pending autosaves
        function idbOpen() {
            return new Promise((resolve, reject) => {
                const req = indexedDB.open('rjournaler-db', 1);
                req.onupgradeneeded = (ev) => {
                    const db = req.result;
                    if (!db.objectStoreNames.contains('autosaves')) {
                        db.createObjectStore('autosaves', { keyPath: 'id', autoIncrement: true });
                    }
                };
                req.onsuccess = () => resolve(req.result);
                req.onerror = () => reject(req.error);
            });
        }

        async function idbSavePending(obj) {
            const db = await idbOpen();
            return new Promise((resolve, reject) => {
                const tx = db.transaction('autosaves', 'readwrite');
                const store = tx.objectStore('autosaves');
                const req = store.add({ data: obj, queuedAt: obj._queued_at, entry_uid: obj.entry_uid || '' });
                req.onsuccess = () => resolve(req.result);
                req.onerror = () => reject(req.error);
            });
        }

        async function idbGetAllPending() {
            const db = await idbOpen();
            return new Promise((resolve, reject) => {
                const tx = db.transaction('autosaves', 'readonly');
                const store = tx.objectStore('autosaves');
                const req = store.getAll();
                req.onsuccess = () => resolve(req.result || []);
                req.onerror = () => reject(req.error);
            });
        }

        async function idbDeletePending(id) {
            const db = await idbOpen();
            return new Promise((resolve, reject) => {
                const tx = db.transaction('autosaves', 'readwrite');
                const store = tx.objectStore('autosaves');
                const req = store.delete(id);
                req.onsuccess = () => resolve();
                req.onerror = () => reject(req.error);
            });
        }

        // Flush pending autosaves stored in IndexedDB
        async function tryFlushPendingSaves() {
            if (!navigator.onLine) return;
            try {
                const items = await idbGetAllPending();
                for (const row of items.sort((a,b) => (a.id - b.id))) {
                    try {
                        const body = row.data;
                        const resp = await fetch('/api/entry-autosave.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(body)
                        });
                        if (!resp.ok) {
                            continue;
                        }
                        const data = await resp.json();
                        if (data && data.ok) {
                            await idbDeletePending(row.id);
                            if (data.entry_uid && entryUidInput.value === '') {
                                entryUidInput.value = data.entry_uid;
                                history.replaceState({}, '', '/entry.php?uid=' + encodeURIComponent(data.entry_uid));
                                updateTimelineLink();
                            }
                        }
                    } catch (_err) {
                        // stop and try again later
                        return;
                    }
                }
            } catch (_e) {
                // ignore DB issues
            }
        }

        // Register service worker and attempt to flush pending saves on online
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', async () => {
                try { await navigator.serviceWorker.register('/sw.js'); } catch (_) {}
            });
        }

        window.addEventListener('online', () => {
            tryFlushPendingSaves();
        });

        // Try flushing any pending saves on startup (if online).
        tryFlushPendingSaves();

        // Pending saves UI
        const flushPendingButton = document.getElementById('flush-pending-button');
        async function getPendingCount() {
            try {
                const list = await idbGetAllPending();
                return Array.isArray(list) ? list.length : 0;
            } catch (_e) { return 0; }
        }

        async function updatePendingIndicator() {
            try {
                const count = await getPendingCount();
                if (flushPendingButton) {
                    flushPendingButton.hidden = count === 0;
                    flushPendingButton.textContent = 'Flush pending (' + String(count) + ')';
                }
            } catch (_err) {
                // ignore
            }
        }

        if (flushPendingButton) {
            flushPendingButton.addEventListener('click', async () => {
                setSaveStatus('Flushing pending saves...', 'muted', 'Flushing');
                await tryFlushPendingSaves();
                await updatePendingIndicator();
                setSaveStatus('Flush complete', 'ok', 'Flushed');
            });
        }

        // Keep indicator up to date
        window.addEventListener('storage', updatePendingIndicator);
        window.addEventListener('online', updatePendingIndicator);
        updatePendingIndicator();

        async function saveEntry() {
            saveInFlight = true;
            normalizeEditorContentForPersist();
            setSaveStatus('Saving entry...', 'muted', 'Saving');

            try {
                const response = await fetch('/api/entry-save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload())
                });

                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error(data && data.error ? data.error : 'Save failed');
                }

                entryUidInput.value = String(data.entry_uid || '');
                if (typeof data.stage === 'string' && data.stage !== '') {
                    currentStage = data.stage;
                }
                history.replaceState({}, '', '/entry.php?uid=' + encodeURIComponent(entryUidInput.value));
                computeStats(textarea.value);
                refreshStageUi();
                setSaveStatus('Saved', 'ok', 'Saved');
            } catch (error) {
                setSaveStatus(error instanceof Error ? error.message : 'Save failed', 'error', 'Error');
            } finally {
                saveInFlight = false;
            }
        }

        async function callStageAction(endpoint, successMessage) {
            if (endpoint.indexOf('finish') !== -1) {
                await saveEntry();
                if (saveStatus.className === 'error') {
                    return;
                }
            }

            if (entryUidInput.value === '') {
                setSaveStatus('Save entry first', 'error', 'Error');
                return;
            }

            setSaveStatus('Updating stage...', 'muted', 'Working');
            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        _csrf: csrfTokenInput.value,
                        entry_uid: entryUidInput.value
                    })
                });
                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error(data && data.error ? data.error : 'Stage update failed');
                }
                if (typeof data.stage === 'string' && data.stage !== '') {
                    currentStage = data.stage;
                }
                if (endpoint.indexOf('finish') !== -1) {
                    isBodyLocked = true;
                }
                if (endpoint.indexOf('reprocess') !== -1) {
                    isBodyLocked = false;
                }
                if (endpoint.indexOf('unlock') !== -1) {
                    isBodyLocked = false;
                }
                refreshStageUi();
                setSaveStatus(successMessage, 'ok', 'Updated');
            } catch (error) {
                setSaveStatus(error instanceof Error ? error.message : 'Stage update failed', 'error', 'Error');
            }
        }

        async function callReprocessAction() {
            if (entryUidInput.value === '') {
                setSaveStatus('Save entry first', 'error', 'Error');
                return;
            }

            const selectedStageIds = await openReprocessModal();
            if (!Array.isArray(selectedStageIds) || selectedStageIds.length === 0) {
                return;
            }

            setSaveStatus('Updating stage...', 'muted', 'Working');
            try {
                const response = await fetch('/api/entry-reprocess.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        _csrf: csrfTokenInput.value,
                        entry_uid: entryUidInput.value,
                        pipeline_stage_ids: selectedStageIds,
                    })
                });
                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error(data && data.error ? data.error : 'Stage update failed');
                }
                if (typeof data.stage === 'string' && data.stage !== '') {
                    currentStage = data.stage;
                }
                isBodyLocked = false;
                refreshStageUi();
                setSaveStatus('Entry reopened and queued for reprocessing', 'ok', 'Updated');
            } catch (error) {
                setSaveStatus(error instanceof Error ? error.message : 'Stage update failed', 'error', 'Error');
            }
        }

        async function deleteEntry() {
            if (entryUidInput.value === '') {
                return;
            }
            const confirmed = window.confirm('Are you sure you want to delete this entry? This cannot be undone.');
            if (!confirmed) {
                return;
            }

            setSaveStatus('Deleting entry...', 'muted', 'Working');
            try {
                const response = await fetch('/api/entry-delete.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        _csrf: csrfTokenInput.value,
                        entry_uid: entryUidInput.value
                    })
                });
                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error(data && data.error ? data.error : 'Delete failed');
                }

                entryUidInput.value = '';
                dateInput.value = defaultEntryDate;
                titleInput.value = defaultEntryTitle;
                textarea.value = '';
                syncEditorFromMarkdown();
                currentStage = 'AUTOSAVE';
                isBodyLocked = false;
                history.replaceState({}, '', '/entry.php');
                computeStats('');
                updateTimelineLink();
                refreshStageUi();
                setSaveStatus('Entry deleted', 'ok', 'Deleted');
            } catch (error) {
                setSaveStatus(error instanceof Error ? error.message : 'Delete failed', 'error', 'Error');
            }
        }

        function scheduleAutosave() {
            syncMarkdownFromEditor();
            computeStats(textarea.value);
            clearTimeout(autosaveTimer);
            autosaveTimer = setTimeout(autosaveDraft, 900);
        }

        function appendLocalTimeHeading() {
            syncMarkdownFromEditor();

            const stampText = new Intl.DateTimeFormat('en-US', {
                timeZone: userTimeZone || 'America/Chicago',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true,
            }).format(new Date());
            const stamp = '#### ' + stampText;

            const current = textarea.value.trimEnd();
            textarea.value = current === '' ? stamp : current + '\n\n' + stamp;
            syncEditorFromMarkdown();
            if (editorSurface) {
                editorSurface.focus();
            }
            scheduleAutosave();
        }

        if (editorSurface) {
            editorSurface.addEventListener('input', scheduleAutosave);
        }
        titleInput.addEventListener('input', scheduleAutosave);
        dateInput.addEventListener('change', () => {
            const generatedTitle = titleFromEntryDate(dateInput.value);
            if (generatedTitle !== '') {
                titleInput.value = generatedTitle;
            }
            scheduleAutosave();
        });
        dateInput.addEventListener('change', updateTimelineLink);
        if (entryLocationSelect) {
            entryLocationSelect.addEventListener('change', scheduleAutosave);
        }

        document.getElementById('save-button').addEventListener('click', saveEntry);
        finishButton.addEventListener('click', () => callStageAction('/api/entry-finish.php', 'Entry locked and queued for processing'));
        reprocessButton.addEventListener('click', callReprocessAction);
        if (reprocessModalSelectAll) {
            reprocessModalSelectAll.addEventListener('click', () => setAllReprocessStageSelections(true));
        }
        if (reprocessModalClearAll) {
            reprocessModalClearAll.addEventListener('click', () => setAllReprocessStageSelections(false));
        }
        unlockButton.addEventListener('click', () => callStageAction('/api/entry-unlock.php', 'Entry unlocked and stage set to WRITTEN'));
        finalizeButton.addEventListener('click', () => callStageAction('/api/entry-finalize.php', 'Entry marked as final'));
        deleteButton.addEventListener('click', deleteEntry);
        window.addEventListener('resize', () => {
            syncModeClasses();
            const portraitNow = isPortraitMobileViewport();
            const orientationChanged = portraitNow !== lastPortraitMobile;

            if (!isMobileViewport() && document.body.classList.contains('editor-mobile-fullscreen')) {
                document.body.classList.remove('editor-mobile-fullscreen');
                if (document.documentElement) {
                    document.documentElement.classList.remove('editor-mobile-fullscreen');
                }
            }

            if (document.body.classList.contains('editor-mobile-fullscreen')) {
                lockFullscreenViewportHeight();
            }

            if (orientationChanged) {
                lastPortraitMobile = portraitNow;
                refreshStatsLabels();
                computeStats(textarea.value);
                lockFullscreenViewportHeight();
            }

            lockDesktopEditorHeight();

            refreshMobileFullscreenButton();
        });

        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', () => {
                if (document.body.classList.contains('editor-mobile-fullscreen')) {
                    lockFullscreenViewportHeight();
                }
            });
        }

        if (editorToolbar) {
            editorToolbar.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) {
                    return;
                }
                const button = target.closest('button[data-editor-action]');
                if (!(button instanceof HTMLButtonElement)) {
                    return;
                }
                if (button.disabled) {
                    return;
                }

                const custom = button.getAttribute('data-custom');
                if (custom === 'insert-time-heading') {
                    appendLocalTimeHeading();
                    return;
                }
                if (custom === 'toggle-mobile-fullscreen') {
                    toggleMobileFullscreen();
                    return;
                }

                const cmd = button.getAttribute('data-cmd');
                const block = button.getAttribute('data-block');
                if (editorSurface) {
                    editorSurface.focus();
                }
                if (cmd) {
                    document.execCommand(cmd, false);
                } else if (block) {
                    document.execCommand('formatBlock', false, '<' + block + '>');
                }
                scheduleAutosave();
            });
        }

        if (editorHeadingSelect instanceof HTMLSelectElement) {
            editorHeadingSelect.addEventListener('change', () => {
                const block = String(editorHeadingSelect.value || '').trim();
                if (block === '') {
                    return;
                }
                if (editorSurface) {
                    editorSurface.focus();
                }
                document.execCommand('formatBlock', false, '<' + block + '>');
                editorHeadingSelect.value = '';
                scheduleAutosave();
            });
        }

        if (initialEntry && typeof initialEntry.content_raw === 'string' && textarea.value === '') {
            textarea.value = initialEntry.content_raw;
        }

        syncEditorFromMarkdown();

        if (metaPanel) {
            const tabButtons = metaPanel.querySelectorAll('.meta-tab');
            const panes = metaPanel.querySelectorAll('.meta-pane');
            tabButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    const target = button.getAttribute('data-tab');
                    tabButtons.forEach((candidate) => {
                        const selected = candidate === button;
                        candidate.classList.toggle('active', selected);
                        candidate.setAttribute('aria-selected', selected ? 'true' : 'false');
                    });
                    panes.forEach((pane) => {
                        const active = pane.getAttribute('data-pane') === target;
                        pane.classList.toggle('active', active);
                    });
                });
            });
        }

        setMode(getSavedMode());

        computeStats(textarea.value);
        refreshStatsLabels();
        updateTimelineLink();
        refreshStageUi();
        lastPortraitMobile = isPortraitMobileViewport();
        lockFullscreenViewportHeight();
        lockDesktopEditorHeight();
        refreshMobileFullscreenButton();
    </script>
</body>
</html>
