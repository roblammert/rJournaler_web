<?php

declare(strict_types=1);

namespace App\Entry;

use PDO;

final class EntryRepository
{
    /** @var array<string,string> */
    private const DEFAULT_WEATHER_LOCATION = [
        'key' => 'new_richmond_wi',
        'label' => 'New Richmond, WI, US',
        'city' => 'New Richmond',
        'state' => 'WI',
        'zip' => '54017',
        'country' => 'US',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $entryUidAppVersionCode = 'W010000'
    ) {
        if (!EntryUid::isValidAppVersionCode($this->entryUidAppVersionCode)) {
            throw new \InvalidArgumentException('Invalid configured entry UID app version code.');
        }
    }

    private ?bool $weatherColumnsAvailable = null;

    public function findByUidForUser(string $entryUid, int $userId): ?array
    {
        $selectWeather = $this->hasWeatherColumns()
            ? 'weather_location_key, weather_location_json'
            : "'' AS weather_location_key, NULL AS weather_location_json";

        $stmt = $this->pdo->prepare(
            'SELECT id, entry_uid, user_id, entry_date, ' . $selectWeather . ', title, content_raw, word_count, workflow_stage, body_locked, updated_at FROM journal_entries WHERE entry_uid = :entry_uid AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute([
            'entry_uid' => $entryUid,
            'user_id' => $userId,
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function recentForUser(int $userId, int $limit = 20): array
    {
        $selectWeather = $this->hasWeatherColumns()
            ? 'weather_location_key, weather_location_json'
            : "'' AS weather_location_key, NULL AS weather_location_json";

        $stmt = $this->pdo->prepare(
            'SELECT entry_uid, entry_date, ' . $selectWeather . ', title, word_count, workflow_stage, updated_at FROM journal_entries WHERE user_id = :user_id ORDER BY updated_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    public function findLatestByDateForUser(int $userId, string $entryDate): ?array
    {
        $selectWeather = $this->hasWeatherColumns()
            ? 'weather_location_key, weather_location_json'
            : "'' AS weather_location_key, NULL AS weather_location_json";

        $stmt = $this->pdo->prepare(
                        'SELECT id, entry_uid, user_id, entry_date, ' . $selectWeather . ', title, content_raw, word_count, workflow_stage, body_locked, updated_at
             FROM journal_entries
             WHERE user_id = :user_id
               AND entry_date = :entry_date
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'entry_date' => $entryDate,
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function create(int $userId, string $entryDate, string $title, string $content, array $weatherLocation = [], ?string $weatherLocationKey = null): string
    {
        $wordCount = self::wordCount($content);
        $entryUid = $this->generateUniqueUid();
        $normalizedWeather = $this->normalizeWeatherLocation($weatherLocation, $weatherLocationKey);

        if ($this->hasWeatherColumns()) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO journal_entries (entry_uid, user_id, entry_date, weather_location_key, weather_location_json, title, content_raw, content_html, word_count, workflow_stage, body_locked, stage_updated_at) VALUES (:entry_uid, :user_id, :entry_date, :weather_location_key, :weather_location_json, :title, :content_raw, NULL, :word_count, :workflow_stage, 0, UTC_TIMESTAMP())'
            );
            $stmt->execute([
                'entry_uid' => $entryUid,
                'user_id' => $userId,
                'entry_date' => $entryDate,
                'weather_location_key' => $normalizedWeather['key'],
                'weather_location_json' => json_encode($normalizedWeather, JSON_UNESCAPED_SLASHES),
                'title' => $title,
                'content_raw' => $content,
                'word_count' => $wordCount,
                'workflow_stage' => 'AUTOSAVE',
            ]);
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO journal_entries (entry_uid, user_id, entry_date, title, content_raw, content_html, word_count, workflow_stage, body_locked, stage_updated_at) VALUES (:entry_uid, :user_id, :entry_date, :title, :content_raw, NULL, :word_count, :workflow_stage, 0, UTC_TIMESTAMP())'
            );
            $stmt->execute([
                'entry_uid' => $entryUid,
                'user_id' => $userId,
                'entry_date' => $entryDate,
                'title' => $title,
                'content_raw' => $content,
                'word_count' => $wordCount,
                'workflow_stage' => 'AUTOSAVE',
            ]);
        }

        return $entryUid;
    }

    public function updateByUid(string $entryUid, int $userId, string $entryDate, string $title, string $content, array $weatherLocation = [], ?string $weatherLocationKey = null): bool
    {
        $wordCount = self::wordCount($content);
        $normalizedWeather = $this->normalizeWeatherLocation($weatherLocation, $weatherLocationKey);

        if ($this->hasWeatherColumns()) {
            $stmt = $this->pdo->prepare(
                'UPDATE journal_entries
                 SET entry_date = :entry_date,
                     weather_location_key = :weather_location_key,
                     weather_location_json = :weather_location_json,
                     title = :title,
                     content_raw = :content_raw,
                     content_html = NULL,
                     word_count = :word_count,
                     updated_at = UTC_TIMESTAMP()
                 WHERE entry_uid = :entry_uid
                   AND user_id = :user_id
                   AND body_locked = 0
                   AND workflow_stage NOT IN (\'COMPLETE\', \'FINAL\')'
            );
            $stmt->execute([
                'entry_date' => $entryDate,
                'weather_location_key' => $normalizedWeather['key'],
                'weather_location_json' => json_encode($normalizedWeather, JSON_UNESCAPED_SLASHES),
                'title' => $title,
                'content_raw' => $content,
                'word_count' => $wordCount,
                'entry_uid' => $entryUid,
                'user_id' => $userId,
            ]);
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE journal_entries
                 SET entry_date = :entry_date,
                     title = :title,
                     content_raw = :content_raw,
                     content_html = NULL,
                     word_count = :word_count,
                     updated_at = UTC_TIMESTAMP()
                 WHERE entry_uid = :entry_uid
                   AND user_id = :user_id
                   AND body_locked = 0
                   AND workflow_stage NOT IN (\'COMPLETE\', \'FINAL\')'
            );
            $stmt->execute([
                'entry_date' => $entryDate,
                'title' => $title,
                'content_raw' => $content,
                'word_count' => $wordCount,
                'entry_uid' => $entryUid,
                'user_id' => $userId,
            ]);
        }

        return $stmt->rowCount() > 0;
    }

    public function setStageForUser(string $entryUid, int $userId, string $stage): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE journal_entries SET workflow_stage = :workflow_stage, stage_updated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE entry_uid = :entry_uid AND user_id = :user_id'
        );
        $stmt->execute([
            'workflow_stage' => $stage,
            'entry_uid' => $entryUid,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function lockBodyForUser(string $entryUid, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE journal_entries SET body_locked = 1, workflow_stage = :workflow_stage, stage_updated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE entry_uid = :entry_uid AND user_id = :user_id'
        );
        $stmt->execute([
            'workflow_stage' => 'FINISHED',
            'entry_uid' => $entryUid,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function hasRequiredMetadata(string $entryUid): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                EXISTS(SELECT 1 FROM entry_meta_group_0 WHERE entry_uid = :entry_uid) AS has_g0,
                EXISTS(SELECT 1 FROM entry_meta_group_1 WHERE entry_uid = :entry_uid) AS has_g1,
                EXISTS(SELECT 1 FROM entry_meta_group_2 WHERE entry_uid = :entry_uid) AS has_g2,
                EXISTS(SELECT 1 FROM entry_meta_group_3 WHERE entry_uid = :entry_uid) AS has_g3'
        );
        $stmt->execute(['entry_uid' => $entryUid]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return false;
        }

        return (int) ($row['has_g0'] ?? 0) === 1
            && (int) ($row['has_g1'] ?? 0) === 1
            && (int) ($row['has_g2'] ?? 0) === 1
            && (int) ($row['has_g3'] ?? 0) === 1;
    }

    public function unlockBodyForUser(string $entryUid, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE journal_entries SET body_locked = 0, workflow_stage = :workflow_stage, stage_updated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE entry_uid = :entry_uid AND user_id = :user_id'
        );
        $stmt->execute([
            'workflow_stage' => 'REPROCESS',
            'entry_uid' => $entryUid,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public static function wordCount(string $text): int
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return 0;
        }

        $parts = preg_split('/\s+/', $trimmed);
        return is_array($parts) ? count($parts) : 0;
    }

    private function generateUniqueUid(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = EntryUid::generate('rjournaler', $this->entryUidAppVersionCode);
            if (!$this->existsByUid($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to generate unique entry UID');
    }

    private function existsByUid(string $entryUid): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM journal_entries WHERE entry_uid = :entry_uid LIMIT 1');
        $stmt->execute(['entry_uid' => $entryUid]);

        return (bool) $stmt->fetchColumn();
    }

    private function hasWeatherColumns(): bool
    {
        if (is_bool($this->weatherColumnsAvailable)) {
            return $this->weatherColumnsAvailable;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME IN (\'weather_location_key\', \'weather_location_json\')'
        );
        $stmt->execute(['table_name' => 'journal_entries']);
        $this->weatherColumnsAvailable = ((int) $stmt->fetchColumn() >= 2);

        return $this->weatherColumnsAvailable;
    }

    /**
     * @param array<string,mixed> $weatherLocation
     * @return array<string,string>
     */
    private function normalizeWeatherLocation(array $weatherLocation, ?string $weatherLocationKey): array
    {
        $key = trim((string) ($weatherLocationKey ?? ($weatherLocation['key'] ?? '')));
        if ($key === '') {
            $key = self::DEFAULT_WEATHER_LOCATION['key'];
        }

        $normalized = [
            'key' => $key,
            'label' => trim((string) ($weatherLocation['label'] ?? '')),
            'city' => trim((string) ($weatherLocation['city'] ?? '')),
            'state' => trim((string) ($weatherLocation['state'] ?? '')),
            'zip' => trim((string) ($weatherLocation['zip'] ?? '')),
            'country' => strtoupper(trim((string) ($weatherLocation['country'] ?? ''))),
        ];

        if ($normalized['country'] === '') {
            $normalized['country'] = self::DEFAULT_WEATHER_LOCATION['country'];
        }
        if ($normalized['city'] === '') {
            $normalized['city'] = self::DEFAULT_WEATHER_LOCATION['city'];
        }
        if ($normalized['state'] === '') {
            $normalized['state'] = self::DEFAULT_WEATHER_LOCATION['state'];
        }
        if ($normalized['zip'] === '') {
            $normalized['zip'] = self::DEFAULT_WEATHER_LOCATION['zip'];
        }
        if ($normalized['label'] === '') {
            $normalized['label'] = $normalized['city'] . ', ' . $normalized['state'] . ', ' . $normalized['country'];
        }

        return $normalized;
    }
}
