<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Резервное копирование БД (ТЗ §11 «резервное копирование, восстановление данных»).
 *
 * sqlite   — снимок через VACUUM INTO (консистентная копия, не «сырое»
 *            копирование файла при активных записях/WAL).
 * mysql    — mysqldump (--single-transaction, пароль через MYSQL_PWD,
 *            не через CLI-аргументы, чтобы не светить в ps).
 *
 * Файлы кладутся в storage/app/backups (вне public-диска — к бэкапам с ПДн
 * нет веб-доступа), старые копии ротируются по количеству.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup
        {--connection= : Имя подключения из config/database.php, по умолчанию default}
        {--keep= : Сколько последних копий хранить, по умолчанию из config/backup.php}';

    protected $description = 'Сделать резервную копию БД с ротацией старых копий';

    public function handle(): int
    {
        $name = (string) ($this->option('connection') ?: config('database.default'));
        $config = config("database.connections.{$name}");

        if (! is_array($config)) {
            $this->error("Подключение [{$name}] не найдено в config/database.php");

            return self::INVALID;
        }

        $dir = (string) config('backup.path');
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->error("Не удалось создать каталог бэкапов: {$dir}");

            return self::FAILURE;
        }

        $resolvedDir = str_replace('\\', '/', (string) realpath($dir));
        $publicDir = str_replace('\\', '/', (string) realpath(public_path()));
        if ($resolvedDir === '' || ($publicDir !== '' && (strtolower($resolvedDir) === strtolower($publicDir)
            || str_starts_with(strtolower($resolvedDir), strtolower($publicDir).'/')))) {
            $this->error('Каталог резервных копий должен находиться вне public');

            return self::FAILURE;
        }
        $stamp = now()->format('Y_m_d_His_u').'_'.bin2hex(random_bytes(3));

        try {
            $file = match ($config['driver'] ?? null) {
                'sqlite' => $this->backupSqlite($name, $config, $dir, $stamp),
                'mysql', 'mariadb' => $this->backupMysql($name, $config, $dir, $stamp),
                default => throw new RuntimeException(
                    'Драйвер ['.($config['driver'] ?? 'null').'] не поддерживается этой командой'
                ),
            };
        } catch (Throwable $e) {
            $this->error('Бэкап не выполнен: '.$e->getMessage());
            Log::error('db.backup failed', ['connection' => $name, 'error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $keep = (int) ($this->option('keep') ?: config('backup.keep'));
        @chmod($file, 0600);
        $removed = $this->rotate($dir, $name, $keep);

        $size = (int) @filesize($file);
        $this->info(sprintf(
            'Бэкап создан: %s (%s КБ), удалено старых копий: %d',
            $file,
            number_format($size / 1024, 1, ',', ' '),
            $removed,
        ));
        Log::info('db.backup completed', [
            'connection' => $name,
            'file' => $file,
            'size' => $size,
            'removed' => $removed,
        ]);

        return self::SUCCESS;
    }

    /** Снимок sqlite-БД через VACUUM INTO (вне транзакций — отдельное PDO). */
    private function backupSqlite(string $name, array $config, string $dir, string $stamp): string
    {
        $source = (string) ($config['database'] ?? '');

        if ($source === ':memory:' || $source === '') {
            throw new RuntimeException('БД в памяти (:memory:) не подлежит файловому бэкапу');
        }

        if (! is_file($source)) {
            throw new RuntimeException("Файл БД не найден: {$source}");
        }

        $target = $dir."/{$name}_{$stamp}.sqlite";

        // отдельное соединение: снимок консистентен и не зависит от
        // транзакций текущего соединения приложения
        $pdo = new PDO('sqlite:'.$source);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('VACUUM INTO '.$pdo->quote($target));

        if (! is_file($target) || filesize($target) === 0) {
            throw new RuntimeException("Снимок не создан или пуст: {$target}");
        }

        return $target;
    }

    /** Дамп mysql/mariadb через mysqldump. */
    private function backupMysql(string $name, array $config, string $dir, string $stamp): string
    {
        $binary = (string) (env('DB_DUMP_BINARY') ?: 'mysqldump');
        $target = $dir."/{$name}_{$stamp}.sql";

        $process = new Process([
            $binary,
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? '3306'),
            '--user='.($config['username'] ?? 'root'),
            '--single-transaction',
            '--routines',
            '--triggers',
            '--no-tablespaces',
            '--result-file='.$target,
            (string) ($config['database'] ?? ''),
        ], null, ['MYSQL_PWD' => (string) ($config['password'] ?? '')], null, 600);

        $process->run();

        if (! $process->isSuccessful()) {
            @unlink($target);
            $detail = trim($process->getErrorOutput()) ?: 'неизвестная ошибка';
            $hint = str_contains($detail, 'not found') || str_contains($detail, 'ENOENT')
                ? ' (проверьте наличие mysqldump в PATH или задайте DB_DUMP_BINARY)'
                : '';

            throw new RuntimeException("mysqldump завершился с ошибкой: {$detail}{$hint}");
        }

        if (! is_file($target) || filesize($target) === 0) {
            throw new RuntimeException("Дамп не создан или пуст: {$target}");
        }

        return $target;
    }

    /** Ротация: оставляем $keep свежайших копий, остальные удаляем. */
    private function rotate(string $dir, string $name, int $keep): int
    {
        if ($keep < 1) {
            return 0;
        }

        $files = array_merge(
            glob($dir.'/'.$name.'_*.sql') ?: [],
            glob($dir.'/'.$name.'_*.sqlite') ?: [],
        );

        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        $removed = 0;
        foreach (array_slice($files, $keep) as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }
}
