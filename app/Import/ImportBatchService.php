<?php

declare(strict_types=1);

namespace App\Import;

use App\Entry\EntryRepository;
use App\Entry\EntryUid;
use PDO;
use RuntimeException;
use ZipArchive;

final class ImportBatchService
{
    public function __construct(
        private readonly MonthlyMarkdownParser $parser = new MonthlyMarkdownParser('America/Chicago')
    ) {
    }

    public function stageZipImport(PDO $pdo, int $userId, string $zipFilePath, string $sourceName): array
    {
        return $this->stageZipImportWithUidVersionCode($pdo, $userId, $zipFilePath, $sourceName, null);
    }

    public function stageZipImportWithUidDigits(PDO $pdo, int $userId, string $zipFilePath, string $sourceName, ?string $uidVersionDigits): array
    {
        return $this->stageZipImportWithUidVersionCode($pdo, $userId, $zipFilePath, $sourceName, $uidVersionDigits);
    }

    public function stageZipImportWithUidVersionCode(PDO $pdo, int $userId, string $zipFilePath, string $sourceName, ?string $uidVersionCode): array
    {
        $resolvedUidVersionCode = $this->normalizeUidVersionCode($uidVersionCode);

        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                'ZIP support is not enabled in PHP. Enable the zip extension (ext-zip) and restart PHP.'
            );
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFilePath) !== true) {
            throw new RuntimeException('Unable to open ZIP archive.');
        }

        $batchId = null;
        $insertBatch = $pdo->prepare(
            'INSERT INTO import_batches (user_id, source_name, uid_version_digits, status, entry_count, created_at)
             VALUES (:user_id, :source_name, :uid_version_digits, :status, 0, UTC_TIMESTAMP())'
        );
        $insertEntry = $pdo->prepare(
            'INSERT INTO import_entries_temp (batch_id, user_id, source_path, parsed_order, entry_date, entry_title, entry_time_local, entry_created_utc, content_markdown, created_at)
             VALUES (:batch_id, :user_id, :source_path, :parsed_order, :entry_date, :entry_title, :entry_time_local, :entry_created_utc, :content_markdown, UTC_TIMESTAMP())'
        );

        $parsedOrder = 1;
        $entryCount = 0;

        try {
            $pdo->beginTransaction();
            $insertBatch->execute([
                'user_id' => $userId,
                'source_name' => $sourceName,
                'uid_version_digits' => $resolvedUidVersionCode,
                'status' => 'parsed',
            ]);
            $batchId = (int) $pdo->lastInsertId();

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    continue;
                }
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }

                $baseName = basename($name);
                if (preg_match('/^[0-9]{4}-[0-9]{2}\.txt$/i', $baseName) !== 1) {
                    continue;
                }

                $content = $zip->getFromIndex($i);
                if (!is_string($content)) {
                    continue;
                }

                $entries = $this->parser->parseMonthFile($content, $name);
                foreach ($entries as $entry) {
                    $insertEntry->execute([
                        'batch_id' => $batchId,
                        'user_id' => $userId,
                        'source_path' => (string) ($entry['source_path'] ?? $name),
                        'parsed_order' => $parsedOrder,
                        'entry_date' => (string) ($entry['entry_date'] ?? ''),
                        'entry_title' => (string) ($entry['entry_title'] ?? ''),
                        'entry_time_local' => (string) ($entry['entry_time_local'] ?? ''),
                        'entry_created_utc' => (string) ($entry['entry_created_utc'] ?? ''),
                        'content_markdown' => (string) ($entry['content_markdown'] ?? ''),
                    ]);
                    $parsedOrder++;
                    $entryCount++;
                }
            }

            if ($entryCount === 0) {
                throw new RuntimeException('No importable YYYY-MM.txt files were found in the ZIP.');
            }

            $updateBatch = $pdo->prepare('UPDATE import_batches SET entry_count = :entry_count WHERE id = :id');
            $updateBatch->execute([
                'entry_count' => $entryCount,
                'id' => $batchId,
            ]);

            $pdo->commit();
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (is_int($batchId) && $batchId > 0) {
                $this->deleteBatchRows($pdo, $batchId, $userId);
            }
            throw $throwable;
        } finally {
            $zip->close();
        }

        return [
            'batch_id' => $batchId,
            'entry_count' => $entryCount,
        ];
    }

    public function getBatch(PDO $pdo, int $batchId, int $userId): ?array
    {
        $stmt = $pdo->prepare('SELECT id, user_id, source_name, uid_version_digits, status, entry_count, created_at FROM import_batches WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute(['id' => $batchId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function listRecentBatches(PDO $pdo, int $userId, int $limit = 10): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, source_name, uid_version_digits, status, entry_count, accepted_at, denied_at, created_at
             FROM import_batches
             WHERE user_id = :user_id
             ORDER BY created_at DESC, id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public function listBatchEntries(PDO $pdo, int $batchId, int $userId, int $offset, int $limit): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, source_path, parsed_order, entry_date, entry_title, entry_time_local, entry_created_utc, content_markdown
             FROM import_entries_temp
             WHERE batch_id = :batch_id AND user_id = :user_id
             ORDER BY parsed_order ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':batch_id', $batchId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public function countBatchEntries(PDO $pdo, int $batchId, int $userId): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM import_entries_temp WHERE batch_id = :batch_id AND user_id = :user_id');
        $stmt->execute(['batch_id' => $batchId, 'user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function denyBatch(PDO $pdo, int $batchId, int $userId): void
    {
        $pdo->beginTransaction();
        try {
            $this->deleteBatchRows($pdo, $batchId, $userId);
            $stmt = $pdo->prepare('UPDATE import_batches SET status = :status, denied_at = UTC_TIMESTAMP() WHERE id = :id AND user_id = :user_id');
            $stmt->execute(['status' => 'denied', 'id' => $batchId, 'user_id' => $userId]);
            $pdo->commit();
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $throwable;
        }
    }

    public function acceptBatch(PDO $pdo, int $batchId, int $userId, string $defaultAppVersionCode): int
    {
        $batch = $this->getBatch($pdo, $batchId, $userId);
        if (!is_array($batch)) {
            throw new RuntimeException('Import batch not found.');
        }
        $appVersionCode = $this->buildAppVersionCodeFromDigits(
            (string) ($batch['uid_version_digits'] ?? ''),
            $defaultAppVersionCode
        );

        $entriesStmt = $pdo->prepare(
            'SELECT parsed_order, entry_date, entry_title, entry_created_utc, content_markdown
             FROM import_entries_temp
             WHERE batch_id = :batch_id AND user_id = :user_id
             ORDER BY parsed_order ASC'
        );
        $entriesStmt->execute(['batch_id' => $batchId, 'user_id' => $userId]);
        $entries = $entriesStmt->fetchAll() ?: [];
        if (count($entries) === 0) {
            throw new RuntimeException('No staged entries found for this import batch.');
        }

        $insertEntryStmt = $pdo->prepare(
            'INSERT INTO journal_entries (
                entry_uid, user_id, entry_date, title, content_raw, content_html, word_count,
                workflow_stage, body_locked, stage_updated_at, created_at, updated_at
             ) VALUES (
                :entry_uid, :user_id, :entry_date, :title, :content_raw, NULL, :word_count,
                :workflow_stage, 1, :stage_updated_at, :created_at, :updated_at
             )'
        );

        $queueStmt = $pdo->prepare(
            'INSERT INTO worker_jobs (job_type, entry_uid, submitter, stage_label, payload_json, status, priority, attempt_count, run_after, submitted_at)
             VALUES (:job_type, :entry_uid, :submitter, :stage_label, :payload_json, :status, :priority, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );

        $pipelineStages = ['Meta Group 0', 'Meta Group 1', 'Meta Group 2 (LLM)', 'Metrics Finalize'];
        $createdCount = 0;

        $pdo->beginTransaction();
        try {
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $entryUid = EntryUid::generate('rjournaler', $appVersionCode, 'America/Chicago');
                $content = (string) ($entry['content_markdown'] ?? '');
                $createdAt = (string) ($entry['entry_created_utc'] ?? gmdate('Y-m-d H:i:s'));

                $insertEntryStmt->execute([
                    'entry_uid' => $entryUid,
                    'user_id' => $userId,
                    'entry_date' => (string) ($entry['entry_date'] ?? ''),
                    'title' => (string) ($entry['entry_title'] ?? ''),
                    'content_raw' => $content,
                    'word_count' => EntryRepository::wordCount($content),
                    // Imported entries still require metadata pipeline processing.
                    'workflow_stage' => 'IN_PROCESS',
                    'stage_updated_at' => $createdAt,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                $queueStmt->execute([
                    'job_type' => 'entry_process_pipeline',
                    'entry_uid' => $entryUid,
                    'submitter' => 'IMPORT',
                    'stage_label' => 'Queued',
                    'payload_json' => json_encode([
                        'entry_uid' => $entryUid,
                        'user_id' => $userId,
                        'source' => 'import',
                        'pipeline' => [
                            'completed' => [],
                            'remaining_labels' => $pipelineStages,
                        ],
                    ], JSON_UNESCAPED_SLASHES),
                    'status' => 'queued',
                    'priority' => 45,
                ]);

                $createdCount++;
            }

            $this->deleteBatchRows($pdo, $batchId, $userId);
            $updateBatch = $pdo->prepare('UPDATE import_batches SET status = :status, accepted_at = UTC_TIMESTAMP() WHERE id = :id AND user_id = :user_id');
            $updateBatch->execute(['status' => 'accepted', 'id' => $batchId, 'user_id' => $userId]);

            $pdo->commit();
        } catch (\Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $throwable;
        }

        return $createdCount;
    }

    private function deleteBatchRows(PDO $pdo, int $batchId, int $userId): void
    {
        $stmt = $pdo->prepare('DELETE FROM import_entries_temp WHERE batch_id = :batch_id AND user_id = :user_id');
        $stmt->execute(['batch_id' => $batchId, 'user_id' => $userId]);
    }

    private function normalizeUidVersionCode(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $uidVersionCode = strtoupper(trim($value));
        if ($uidVersionCode === '') {
            return null;
        }
        if (preg_match('/^[A-Z][0-9]{6}$/', $uidVersionCode) !== 1) {
            throw new RuntimeException('UID version code must be a letter followed by 6 numbers (example: T032602).');
        }
        return $uidVersionCode;
    }

    private function buildAppVersionCodeFromDigits(string $uidDigits, string $defaultAppVersionCode): string
    {
        $normalizedDefault = strtoupper(trim($defaultAppVersionCode));
        if (preg_match('/^[A-Z][0-9]{6}$/', $uidDigits) === 1) {
            return strtoupper($uidDigits);
        }

        // Backward compatibility for older import batches that stored only the 6-digit suffix.
        if (preg_match('/^[0-9]{6}$/', $uidDigits) === 1) {
            $prefix = preg_match('/^[A-Z]/', $normalizedDefault) === 1 ? substr($normalizedDefault, 0, 1) : 'W';
            return $prefix . $uidDigits;
        }

        if (preg_match('/^[A-Z][0-9]{6}$/', $normalizedDefault) === 1) {
            return $normalizedDefault;
        }

        return 'W010000';
    }
}
