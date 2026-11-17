<?php

declare(strict_types=1);

namespace Tests\Feature;

use PDO;
use Tests\TestCase;

/**
 * Фаза A, задача 6: резервное копирование БД (ТЗ §11) —
 * снимок sqlite, ротация, валидация параметров, ежедневное расписание.
 */
class BackupDatabaseCommandTest extends TestCase
{
    private string $sourceFile;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupDir = sys_get_temp_dir().'/corpms_backup_test_'.uniqid();
        mkdir($this->backupDir.'/src', 0777, true);

        $this->sourceFile = $this->backupDir.'/src/source.sqlite';

        $pdo = new PDO('sqlite:'.$this->sourceFile);
        $pdo->exec('CREATE TABLE demo (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO demo (name) VALUES ('Иванов Иван')");
        unset($pdo);

        config([
            'backup.path' => $this->backupDir,
            'backup.keep' => 14,
            'database.connections.backup_src' => [
                'driver' => 'sqlite',
                'database' => $this->sourceFile,
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir.'/*') ?: [] as $file) {
            if (is_dir($file)) {
                foreach (glob($file.'/*') ?: [] as $inner) {
                    @unlink($inner);
                }
                @rmdir($file);
            } else {
                @unlink($file);
            }
        }
        @rmdir($this->backupDir);

        parent::tearDown();
    }

    public function test_sqlite_backup_creates_consistent_snapshot_with_data(): void
    {
        $this->artisan('db:backup', ['--connection' => 'backup_src'])
            ->assertExitCode(0);

        $snapshots = glob($this->backupDir.'/backup_src_*.sqlite');
        $this->assertCount(1, $snapshots, 'должен быть создан один снимок');

        // снимок открывается как обычная sqlite-БД и содержит данные
        $pdo = new PDO('sqlite:'.$snapshots[0]);
        $name = $pdo->query('SELECT name FROM demo')->fetchColumn();
        unset($pdo);

        $this->assertSame('Иванов Иван', $name, 'данные должны попасть в снимок');
    }

    public function test_rotation_keeps_only_configured_number_of_copies(): void
    {
        // 5 «старых» копий с прошлыми датами
        for ($i = 0; $i < 5; $i++) {
            $file = $this->backupDir."/backup_src_2020_01_0{$i}_000000.sqlite";
            file_put_contents($file, 'old');
            touch($file, time() - 86400 * ($i + 1));
        }

        $this->artisan('db:backup', ['--connection' => 'backup_src', '--keep' => 2])
            ->assertExitCode(0);

        $files = glob($this->backupDir.'/*.sqlite') ?: [];
        $this->assertCount(2, $files, 'после ротации должно остаться ровно 2 копии');
        $this->assertFileExists($this->backupDir.'/backup_src_2020_01_00_000000.sqlite', 'свежайшая из старых копий сохранена');
        $this->assertFileDoesNotExist($this->backupDir.'/backup_src_2020_01_04_000000.sqlite', 'самая старая копия удалена');
    }

    public function test_unknown_connection_fails_cleanly(): void
    {
        $this->artisan('db:backup', ['--connection' => 'no_such_connection'])
            ->assertExitCode(2); // Command::INVALID
    }

    public function test_in_memory_database_is_rejected(): void
    {
        config(['database.connections.backup_mem' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);

        $this->artisan('db:backup', ['--connection' => 'backup_mem'])
            ->assertExitCode(1); // Command::FAILURE
    }

    public function test_backup_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')
            ->assertExitCode(0)
            ->expectsOutputToContain('db:backup');
    }
}
