<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController
{
    public function index(): \Illuminate\View\View
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

    public function runDb(Request $request): \Illuminate\Http\RedirectResponse
    {
        Artisan::call('db:backup', ['--keep' => (int) config('backup.keep')]);
        return redirect()->route('backups.index')->with('status', trim(Artisan::output()));
    }

    public function runFiles(Request $request): \Illuminate\Http\RedirectResponse
    {
        Artisan::call('files:backup', ['--json' => true]);
        return redirect()->route('backups.index')->with('status', trim(Artisan::output()));
    }

    public function download(string $file): BinaryFileResponse
    {
        $dir = (string) config('backup.path');
        $path = $dir.DIRECTORY_SEPARATOR.basename($file);
        if (! is_file($path)) {
            abort(404);
        }
        return response()->download($path);
    }
}