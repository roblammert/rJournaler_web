<?php

declare(strict_types=1);

namespace App\Import;

use DateTimeImmutable;
use DateTimeZone;

final class MonthlyMarkdownParser
{
    public function __construct(
        private readonly string $entryTimeZone = 'America/Chicago'
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseMonthFile(string $content, string $sourcePath): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $matches = [];
        preg_match_all('/^###\s+([0-9]{4}-[0-9]{2}-[0-9]{2})(?:\s*\|\s*([^\n]+))?\s*$/m', $normalized, $matches, PREG_OFFSET_CAPTURE);

        if (!isset($matches[0]) || !is_array($matches[0]) || count($matches[0]) === 0) {
            return [];
        }

        $entries = [];
        for ($i = 0; $i < count($matches[0]); $i++) {
            $fullHeader = (string) $matches[0][$i][0];
            $headerOffset = (int) $matches[0][$i][1];
            $date = (string) $matches[1][$i][0];
            $dow = isset($matches[2][$i][0]) ? trim((string) $matches[2][$i][0]) : '';
            $title = $dow !== '' ? ($date . ' | ' . $dow) : $date;

            $blockStart = $headerOffset + strlen($fullHeader);
            $blockEnd = ($i + 1 < count($matches[0]))
                ? (int) $matches[0][$i + 1][1]
                : strlen($normalized);
            $block = trim(substr($normalized, $blockStart, $blockEnd - $blockStart), "\n");
            if ($block === '') {
                continue;
            }

            $timeMatches = [];
            preg_match_all('/^####\s+([^\n]+)\s*$/m', $block, $timeMatches, PREG_OFFSET_CAPTURE);

            if (!isset($timeMatches[0]) || !is_array($timeMatches[0]) || count($timeMatches[0]) === 0) {
                // No explicit time in this date block: still import as one entry.
                $segment = preg_replace('/^\s*<!--[\s\S]*?-->\s*/', '', $block) ?? $block;
                $segment = trim($segment);
                if ($segment === '') {
                    continue;
                }

                $entries[] = [
                    'source_path' => $sourcePath,
                    'entry_date' => $date,
                    'entry_title' => $title,
                    'entry_time_local' => '',
                    'entry_created_utc' => $this->buildUtcDateTime($date, '00:00'),
                    'content_markdown' => $segment,
                ];
                continue;
            }

            $timeSegments = [];
            for ($j = 0; $j < count($timeMatches[0]); $j++) {
                $timeHeader = (string) $timeMatches[0][$j][0];
                $timeOffset = (int) $timeMatches[0][$j][1];
                $timeValue = trim((string) $timeMatches[1][$j][0]);

                $segmentStart = $timeOffset + strlen($timeHeader);
                $segmentEnd = ($j + 1 < count($timeMatches[0]))
                    ? (int) $timeMatches[0][$j + 1][1]
                    : strlen($block);
                $segment = ltrim(substr($block, $segmentStart, $segmentEnd - $segmentStart), "\n");

                // Remove leading HTML comment metadata block when present.
                $segment = preg_replace('/^\s*<!--[\s\S]*?-->\s*/', '', $segment) ?? $segment;
                $segment = trim($segment);
                if ($segment === '') {
                    continue;
                }

                $createdUtc = $this->buildUtcDateTime($date, $timeValue);

                $timeSegments[] = [
                    'entry_time_local' => $timeValue,
                    'entry_created_utc' => $createdUtc,
                    'content_markdown' => $segment,
                ];
            }

            if (count($timeSegments) === 0) {
                continue;
            }

            $first = $timeSegments[0];
            $composedSegments = [];
            foreach ($timeSegments as $segment) {
                if (!is_array($segment)) {
                    continue;
                }
                $segmentTime = (string) ($segment['entry_time_local'] ?? '');
                $segmentContent = (string) ($segment['content_markdown'] ?? '');
                if (count($timeSegments) > 1) {
                    $composedSegments[] = '#### ' . $segmentTime . "\n" . $segmentContent;
                } else {
                    $composedSegments[] = $segmentContent;
                }
            }

            $entries[] = [
                'source_path' => $sourcePath,
                'entry_date' => $date,
                'entry_title' => $title,
                'entry_time_local' => (string) ($first['entry_time_local'] ?? ''),
                'entry_created_utc' => (string) ($first['entry_created_utc'] ?? ''),
                'content_markdown' => trim(implode("\n\n", $composedSegments)),
            ];
        }

        return $entries;
    }

    private function buildUtcDateTime(string $date, string $timeValue): string
    {
        $timezone = new DateTimeZone($this->entryTimeZone);
        $formats = ['Y-m-d g:i A', 'Y-m-d g:i:s A', 'Y-m-d H:i', 'Y-m-d H:i:s'];

        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $date . ' ' . $timeValue, $timezone);
            if ($dt instanceof DateTimeImmutable) {
                return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }

        $fallback = new DateTimeImmutable($date . ' 00:00:00', $timezone);
        return $fallback->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
