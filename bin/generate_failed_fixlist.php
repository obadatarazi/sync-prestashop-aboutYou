<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use SyncBridge\Database\Database;

$rows = Database::fetchAll(
    "SELECT ps_id, reference, name, sync_error FROM products WHERE sync_status = ? ORDER BY ps_id",
    ['error']
);

$csv = "ps_id,reference,name,missing_description,missing_ean_count,no_valid_variants,sync_error\n";
$summary = [
    'rows' => 0,
    'missing_description' => 0,
    'with_missing_ean' => 0,
    'no_valid_variants' => 0,
];

foreach ($rows as $row) {
    $error = (string) ($row['sync_error'] ?? '');

    $hasMissingDescription = str_contains($error, '[reason=missing_required_text] Missing description')
        || str_contains($error, 'Missing description');
    preg_match_all('/(\[reason=missing_ean\]|missing EAN)/i', $error, $eanMatches);
    $missingEanCount = count($eanMatches[0] ?? []);
    $hasNoValidVariants = str_contains($error, '[reason=no_valid_variants]')
        || str_contains($error, 'No valid variants available for export');

    $summary['rows']++;
    if ($hasMissingDescription) {
        $summary['missing_description']++;
    }
    if ($missingEanCount > 0) {
        $summary['with_missing_ean']++;
    }
    if ($hasNoValidVariants) {
        $summary['no_valid_variants']++;
    }

    $line = [
        (string) ($row['ps_id'] ?? ''),
        (string) ($row['reference'] ?? ''),
        (string) ($row['name'] ?? ''),
        $hasMissingDescription ? 'yes' : 'no',
        (string) $missingEanCount,
        $hasNoValidVariants ? 'yes' : 'no',
        str_replace(["\r", "\n"], [' ', ' '], $error),
    ];
    $escaped = array_map(
        static fn (string $value): string => '"' . str_replace('"', '""', $value) . '"',
        $line
    );
    $csv .= implode(',', $escaped) . "\n";
}

if (!is_dir(__DIR__ . '/../reports')) {
    mkdir(__DIR__ . '/../reports', 0777, true);
}

$file = __DIR__ . '/../reports/failed_products_fixlist_' . date('Ymd_His') . '.csv';
file_put_contents($file, $csv);

echo 'file=' . $file . PHP_EOL;
echo 'summary=' . json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL;
