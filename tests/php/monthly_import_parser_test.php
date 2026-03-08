<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/bootstrap.php';

use App\Import\MonthlyMarkdownParser;

$examplePath = dirname(__DIR__, 2) . '/docs/examples/2026-01.txt';
$content = file_get_contents($examplePath);
if (!is_string($content) || $content === '') {
    fwrite(STDERR, "Unable to load example file for parser test.\n");
    exit(1);
}

$parser = new MonthlyMarkdownParser('America/Chicago');
$entries = $parser->parseMonthFile($content, '2026-01.txt');

if (count($entries) !== 31) {
    fwrite(STDERR, 'Expected 31 entries, got ' . count($entries) . "\n");
    exit(1);
}

$first = $entries[0] ?? null;
if (!is_array($first)) {
    fwrite(STDERR, "First parsed entry missing.\n");
    exit(1);
}

if (($first['entry_date'] ?? '') !== '2026-01-01') {
    fwrite(STDERR, "First entry date mismatch.\n");
    exit(1);
}

if (($first['entry_title'] ?? '') !== '2026-01-01 | Thursday') {
    fwrite(STDERR, "First entry title mismatch.\n");
    exit(1);
}

if (($first['entry_time_local'] ?? '') !== '08:06 AM') {
    fwrite(STDERR, "First entry time mismatch.\n");
    exit(1);
}

$firstContent = (string) ($first['content_markdown'] ?? '');
if (str_contains($firstContent, '<!--')) {
    fwrite(STDERR, "HTML comment block should be removed from content.\n");
    exit(1);
}

$jan13Count = 0;
$jan13Entry = null;
foreach ($entries as $entry) {
    if (($entry['entry_date'] ?? '') === '2026-01-13') {
        $jan13Count++;
        $jan13Entry = $entry;
    }
}
if ($jan13Count !== 1) {
    fwrite(STDERR, 'Expected 1 combined entry for 2026-01-13, got ' . $jan13Count . "\n");
    exit(1);
}

$jan13Content = is_array($jan13Entry) ? (string) ($jan13Entry['content_markdown'] ?? '') : '';
if (!str_contains($jan13Content, '#### 08:34 AM')) {
    fwrite(STDERR, "Expected combined entry content to include second time segment heading.\n");
    exit(1);
}

fwrite(STDOUT, "monthly_import_parser_test passed\n");

$sample = <<<'MD'
### 2020-01-01 | Tuesday
the test of the entry begins here
end of entry

### 2020-01-02 | Wednesday
#### 04:00 AM
the test of the entry begins here
end of entry

### 2020-01-03 | Thursday
#### 04:00 AM
the test of the entry begins here

#### 05:30 AM
more entry text
end of entry
MD;

$sampleEntries = $parser->parseMonthFile($sample, 'sample.txt');
if (count($sampleEntries) !== 3) {
    fwrite(STDERR, 'Expected 3 sample entries, got ' . count($sampleEntries) . "\n");
    exit(1);
}

$option1 = $sampleEntries[0] ?? null;
if (!is_array($option1) || (string) ($option1['entry_time_local'] ?? '') !== '') {
    fwrite(STDERR, "Expected option-one entry to have no time value.\n");
    exit(1);
}

$option2 = $sampleEntries[1] ?? null;
if (!is_array($option2) || (string) ($option2['entry_time_local'] ?? '') !== '04:00 AM') {
    fwrite(STDERR, "Expected option-two entry time to be 04:00 AM.\n");
    exit(1);
}

$option3 = $sampleEntries[2] ?? null;
$option3Content = is_array($option3) ? (string) ($option3['content_markdown'] ?? '') : '';
if (!str_contains($option3Content, '#### 05:30 AM')) {
    fwrite(STDERR, "Expected option-three entry to contain second time section.\n");
    exit(1);
}

fwrite(STDOUT, "monthly_import_parser_test sample cases passed\n");
