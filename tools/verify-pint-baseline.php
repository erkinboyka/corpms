<?php

declare(strict_types=1);

/**
 * TEST-05: style-gate без «большого взрыва».
 *
 * В репозитории есть исторический долг по форматированию; этот скрипт
 * запрещает появление НОВЫХ нарушений и позволяет базу постепенно уменьшать.
 * `php tools/verify-pint-baseline.php --update` перегенерирует базу.
 */
$root = dirname(__DIR__);
$baselinePath = $root.'/tools/pint-baseline.json';
$reportPath = sys_get_temp_dir().'/corpms-pint-'.getmypid().'.json';

$normalize = static fn (string $path): string => str_replace('\\', '/', $path);

$readFiles = static function (string $path) use ($normalize): array {
    if (! is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    $names = array_map(
        static fn (mixed $entry): string => is_array($entry) ? (string) ($entry['name'] ?? '') : (string) $entry,
        $data['files'] ?? [],
    );
    $files = array_values(array_filter(array_map($normalize, $names)));
    sort($files);

    return $files;
};

if (in_array('--update', array_slice($argv, 1), true)) {
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/vendor/bin/pint').
        ' --test --output-format=json --output-to-file='.escapeshellarg($reportPath).' --silent';
    exec($command, $output, $code);
    if (! is_file($reportPath)) {
        fwrite(STDERR, "Pint не сформировал отчёт\n");
        exit(1);
    }
    $files = $readFiles($reportPath);
    file_put_contents($baselinePath, json_encode(['files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    unlink($reportPath);
    fwrite(STDOUT, 'База форматирования обновлена: '.count($files)." файлов\n");
    exit(0);
}

$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/vendor/bin/pint').
    ' --test --output-format=json --output-to-file='.escapeshellarg($reportPath).' --silent';
exec($command, $output, $code);
if (! is_file($reportPath)) {
    fwrite(STDERR, "Pint не сформировал отчёт\n");
    exit(1);
}

$baseline = array_fill_keys($readFiles($baselinePath), true);
$current = $readFiles($reportPath);
unlink($reportPath);

$new = array_values(array_filter($current, static fn (string $file): bool => ! isset($baseline[$file])));
$fixed = array_values(array_filter(
    array_keys($baseline),
    static fn (string $file): bool => ! in_array($file, $current, true),
));

if ($new !== []) {
    fwrite(STDERR, "Новые нарушения форматирования (исправьте или обновите базу осознанно):\n - "
        .implode("\n - ", $new)."\n");
    exit(1);
}

fwrite(STDOUT, 'Pint: новых нарушений нет; в базе '.count($fixed).' файлов можно убрать после форматирования.'."\n");
exit(0);
