<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class FilesBackupTest extends TestCase
{
    public function test_private_and_public_attachments_restore_with_verified_content(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('local')->put('documents/test.txt', 'private attachment');
        Storage::disk('public')->put('avatars/test.txt', 'public attachment');
        config(['backup.path' => storage_path('framework/testing/backups-'.uniqid())]);
        $this->assertSame(0, Artisan::call('files:backup', ['--json' => true]));
        $archive = json_decode(Artisan::output(), true)['archive'];
        $this->assertSame(0, Artisan::call('files:verify', ['archive' => $archive, '--json' => true]));
        $result = json_decode(Artisan::output(), true);
        $this->assertSame(2, $result['files']);
        $this->assertSame('private attachment', file_get_contents($result['restored_to'].'/local/documents/test.txt'));
        $zip = new ZipArchive;
        $zip->open($archive);
        $zip->addFromString('local/documents/test.txt', 'corrupted');
        $zip->close();
        $this->assertSame(1, Artisan::call('files:verify', ['archive' => $archive]));
    }

    public function test_archive_path_traversal_is_rejected(): void
    {
        $file = storage_path('framework/testing/unsafe-'.uniqid().'.zip');
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0700, true);
        }
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode(['version' => 1, 'files' => ['local/../../escape.txt' => ['bytes' => 1, 'sha256' => hash('sha256', 'x')]]]));
        $zip->addFromString('local/../../escape.txt', 'x');
        $zip->close();
        $this->assertSame(1, Artisan::call('files:verify', ['archive' => $file]));
    }
}
