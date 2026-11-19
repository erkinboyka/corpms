<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

final class BackupFiles extends Command
{
    protected $signature = 'files:backup {--json}';

    protected $description = 'Создать проверенную копию локальных вложений с контрольными суммами';

    public function handle(): int
    {
        $started = microtime(true);
        $dir = (string) config('backup.path');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $resolved = str_replace('\\', '/', (string) realpath($dir));
        $public = str_replace('\\', '/', (string) realpath(public_path()));
        if ($resolved === '' || str_starts_with(strtolower($resolved).'/', strtolower($public).'/')) {
            $this->error('Backup directory must be outside public');

            return self::FAILURE;
        }
        foreach (['local', 'public'] as $disk) {
            $source = str_replace('\\', '/', (string) realpath((string) config('filesystems.disks.'.$disk.'.root')));
            if ($source !== '' && str_starts_with(strtolower($resolved).'/', strtolower($source).'/')) {
                $this->error('Backup directory must be outside attachment roots');

                return self::FAILURE;
            }
        }
        $file = $resolved.'/files_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(5)).'.zip';
        $zip = new ZipArchive;
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            return self::FAILURE;
        }
        try {
            $manifest = ['version' => 1, 'created_at' => gmdate(DATE_ATOM), 'files' => []];
            foreach (['local', 'public'] as $disk) {
                if (config('filesystems.disks.'.$disk.'.driver') !== 'local') {
                    throw new \RuntimeException('Non-local disks need a provider-specific backup');
                }
                foreach (Storage::disk($disk)->allFiles() as $relative) {
                    if (basename($relative) === '.gitignore') {
                        continue;
                    }
                    $source = Storage::disk($disk)->path($relative);
                    if (is_link($source)) {
                        throw new \RuntimeException('Symlink attachments are not supported');
                    }
                    $entry = $disk.'/'.str_replace('\\', '/', $relative);
                    $manifest['files'][$entry] = ['sha256' => hash_file('sha256', $source), 'bytes' => filesize($source)];
                    if (! $zip->addFile($source, $entry)) {
                        throw new \RuntimeException('Could not add attachment');
                    }
                }
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            $zip->close();
            $zip = new ZipArchive;
            $zip->open($file);
            foreach ($manifest['files'] as $entry => $expected) {
                $stream = $zip->getStream($entry);
                if ($stream === false) {
                    throw new \RuntimeException('Archive entry missing');
                }
                $hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                fclose($stream);
                if (! hash_equals($expected['sha256'], hash_final($hash))) {
                    throw new \RuntimeException('File changed during backup; repeat with writes paused');
                }
            }
            $zip->close();
            chmod($file, 0600);
            $keep = (int) config('backup.keep', 14);
            if ($keep > 0) {
                $archives = glob($resolved.'/files_*.zip') ?: [];
                usort($archives, fn ($a, $b) => filemtime($b) <=> filemtime($a));
                foreach (array_slice($archives, $keep) as $old) {
                    unlink($old);
                }
            }
            $result = ['ok' => true, 'archive' => $file, 'files' => count($manifest['files']), 'duration_s' => round(microtime(true) - $started, 3)];
            $this->line($this->option('json') ? json_encode($result) : 'Files backup verified: '.$file);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            @$zip->close();
            @unlink($file);
            $this->error('Files backup failed: '.get_class($exception));

            return self::FAILURE;
        }
    }
}
