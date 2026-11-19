<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use ZipArchive;

final class VerifyFilesBackup extends Command
{
    protected $signature = 'files:verify {archive} {--json}';

    protected $description = 'Восстановить вложения в новую изолированную папку и сверить контрольные суммы';

    public function handle(): int
    {
        $started = microtime(true);
        $zip = new ZipArchive;
        if ($zip->open((string) $this->argument('archive')) !== true) {
            return self::FAILURE;
        }
        try {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            if (($manifest['version'] ?? null) !== 1 || ! is_array($manifest['files'] ?? null)
                || count($manifest['files']) > 100000 || $zip->numFiles !== count($manifest['files']) + 1) {
                throw new \RuntimeException('Invalid manifest');
            }
            $destination = storage_path('framework/restore-check/'.bin2hex(random_bytes(10)));
            mkdir($destination, 0700, true);
            $total = 0;
            foreach ($manifest['files'] as $entry => $expected) {
                if (! preg_match('~^(local|public)/[^\\\\]+$~', $entry) || str_contains('/'.$entry.'/', '/../')
                    || str_contains('/'.$entry.'/', '/./') || str_contains($entry, ':') || str_contains($entry, "\0")) {
                    throw new \RuntimeException('Unsafe archive path');
                }
                $stat = $zip->statName($entry);
                $total += (int) ($stat['size'] ?? 0);
                if ($stat === false || $stat['size'] !== $expected['bytes'] || $total > 20 * 1024 * 1024 * 1024) {
                    throw new \RuntimeException('Invalid archive size');
                }
                $target = $destination.'/'.$entry;
                if (! is_dir(dirname($target))) {
                    mkdir(dirname($target), 0700, true);
                }
                $input = $zip->getStream($entry);
                $output = fopen($target, 'xb');
                if ($input === false || $output === false) {
                    throw new \RuntimeException('Restore stream could not be opened');
                }
                try {
                    stream_copy_to_stream($input, $output);
                } finally {
                    fclose($input);
                    fclose($output);
                }
                chmod($target, 0600);
                if (! hash_equals($expected['sha256'], hash_file('sha256', $target))) {
                    throw new \RuntimeException('Corrupted attachment');
                }
            }
            $zip->close();
            $result = ['ok' => true, 'files' => count($manifest['files']), 'bytes' => $total, 'restored_to' => $destination,
                'snapshot_created_at' => $manifest['created_at'], 'duration_s' => round(microtime(true) - $started, 3)];
            $this->line($this->option('json') ? json_encode($result) : 'Restored files verified: '.$destination);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            @$zip->close();
            $this->error('Files restore verification failed: '.get_class($exception));

            return self::FAILURE;
        }
    }
}
