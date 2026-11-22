<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditService;
use App\Domains\Backup\Jobs\RunDatabaseBackup;
use App\Domains\Backup\Jobs\RunFilesBackup;
use App\Support\SingleFlightLock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController
{
    public function index(): View
    {
        $dir = (string) config('backup.path');
        $files = [];
        if (is_dir($dir)) {
            foreach (scandir($dir) as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $dir.DIRECTORY_SEPARATOR.$name;
                if (is_file($path)) {
                    $files[] = [
                        'name' => $name,
                        'size' => filesize($path),
                        'mtime' => filemtime($path),
                    ];
                }
            }
        }
        usort($files, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);

        return view('backups.index', ['files' => $files, 'path' => $dir]);
    }

    public function runDb(Request $request): RedirectResponse
    {
        $lockKey = 'backups.db';
        $lockToken = SingleFlightLock::acquire($lockKey);

        if ($lockToken === null) {
            abort(429, 'Резервное копирование БД уже выполняется.');
        }

        try {
            RunDatabaseBackup::dispatch(
                (int) $request->user()->id,
                (int) config('backup.keep'),
                $lockKey,
                $lockToken,
            );
        } catch (\Throwable $exception) {
            SingleFlightLock::release($lockKey, $lockToken);

            throw $exception;
        }

        return redirect()->route('backups.index')->with('status', __('Резервное копирование БД запущено'));
    }

    public function runFiles(Request $request): RedirectResponse
    {
        $lockKey = 'backups.files';
        $lockToken = SingleFlightLock::acquire($lockKey);

        if ($lockToken === null) {
            abort(429, 'Архивация файлов уже выполняется.');
        }

        try {
            RunFilesBackup::dispatch((int) $request->user()->id, $lockKey, $lockToken);
        } catch (\Throwable $exception) {
            SingleFlightLock::release($lockKey, $lockToken);

            throw $exception;
        }

        return redirect()->route('backups.index')->with('status', __('Архивация файлов запущена'));
    }

    public function download(string $file, Request $request, AuditService $audit): BinaryFileResponse
    {
        $dir = (string) config('backup.path');
        $name = basename($file);
        $path = $dir.DIRECTORY_SEPARATOR.$name;
        if (! is_file($path)) {
            abort(404);
        }

        $audit->log($request->user(), 'backup.downloaded', 'backup', null, null, [
            'file' => $name,
            'size' => filesize($path),
        ]);

        return response()->download($path);
    }
}
