<?php

declare(strict_types=1);

/**
 * TEST-05: минимальный порог line coverage по Clover-отчёту PHPUnit.
 * Порог задаётся COVERAGE_MIN (по умолчанию 30%).
 */
$file = $argv[1] ?? 'storage/logs/coverage.xml';
$minimum = (float) (getenv('COVERAGE_MIN') ?: '30');

if (! is_file($file)) {
    fwrite(STDERR, "Clover-отчёт не найден: {$file}\n");
    exit(1);
}

$xml = simplexml_load_string((string) file_get_contents($file));
if ($xml === false || ! isset($xml->project->metrics)) {
    fwrite(STDERR, "Не удалось разобрать Clover-отчёт\n");
    exit(1);
}

$metrics = $xml->project->metrics;
$covered = (int) ($metrics['coveredstatements'] ?? 0);
$total = (int) ($metrics['statements'] ?? 0);

if ($total === 0) {
    fwrite(STDERR, "В отчёте нет данных о покрытии\n");
    exit(1);
}

$percent = round($covered * 100 / $total, 2);
printf("Line coverage: %.2f%% (%d/%d), минимум %.2f%%\n", $percent, $covered, $total, $minimum);

exit($percent + 0.0001 >= $minimum ? 0 : 1);
