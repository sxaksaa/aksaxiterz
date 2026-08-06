<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class DatabaseBackupService
{
    public function create(): string
    {
        $directory = (string) config('backup.path');
        File::ensureDirectoryExists($directory, 0750, true);

        $driver = (string) config('database.default');
        $timestamp = now()->format('Ymd-His');

        $path = match ($driver) {
            'sqlite' => $this->backupSqlite($directory, $timestamp),
            'mysql', 'mariadb' => $this->backupMysql($directory, $timestamp, $driver),
            default => throw new RuntimeException("Database backup is not configured for [{$driver}]."),
        };

        $this->prune($directory);

        return $path;
    }

    private function backupSqlite(string $directory, string $timestamp): string
    {
        $source = (string) config('database.connections.sqlite.database');

        if ($source === ':memory:' || ! is_file($source)) {
            throw new RuntimeException('SQLite backup requires a file-based database.');
        }

        $destination = $directory.DIRECTORY_SEPARATOR."database-{$timestamp}.sqlite";

        if (! File::copy($source, $destination)) {
            throw new RuntimeException('Unable to copy the SQLite database.');
        }

        return $destination;
    }

    private function backupMysql(string $directory, string $timestamp, string $driver): string
    {
        $connection = config("database.connections.{$driver}");
        $sqlPath = $directory.DIRECTORY_SEPARATOR."database-{$timestamp}.sql";
        $gzipPath = $sqlPath.'.gz';
        $binary = $this->mysqldumpBinary();

        $command = [
            $binary,
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
            '--port='.(string) ($connection['port'] ?? 3306),
            '--user='.(string) ($connection['username'] ?? ''),
            '--result-file='.$sqlPath,
            (string) ($connection['database'] ?? ''),
        ];

        $result = Process::env([
            'MYSQL_PWD' => (string) ($connection['password'] ?? ''),
        ])->timeout(600)->run($command);

        if ($result->failed() || ! is_file($sqlPath)) {
            File::delete($sqlPath);
            throw new RuntimeException('mysqldump failed: '.trim($result->errorOutput()));
        }

        $input = fopen($sqlPath, 'rb');
        $output = gzopen($gzipPath, 'wb9');

        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            File::delete([$sqlPath, $gzipPath]);
            throw new RuntimeException('Unable to compress the database backup.');
        }

        while (! feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            if ($chunk === false || gzwrite($output, $chunk) === false) {
                fclose($input);
                gzclose($output);
                File::delete([$sqlPath, $gzipPath]);
                throw new RuntimeException('Unable to compress the database backup.');
            }
        }

        fclose($input);
        gzclose($output);
        File::delete($sqlPath);

        return $gzipPath;
    }

    private function prune(string $directory): void
    {
        $cutoff = now()->subDays((int) config('backup.retention_days'))->getTimestamp();

        collect(File::files($directory))
            ->filter(fn ($file) => str_starts_with($file->getFilename(), 'database-'))
            ->filter(fn ($file) => $file->getMTime() < $cutoff)
            ->each(fn ($file) => File::delete($file->getPathname()));
    }

    private function mysqldumpBinary(): string
    {
        $configured = trim((string) env('MYSQLDUMP_BINARY', 'mysqldump'));

        if ($configured !== '' && $configured !== 'mysqldump') {
            return $configured;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            foreach (['D:/laragon/bin/mysql/*/bin/mysqldump.exe', 'C:/laragon/bin/mysql/*/bin/mysqldump.exe'] as $pattern) {
                $matches = File::glob($pattern);

                if ($matches !== []) {
                    return (string) collect($matches)->sortDesc()->first();
                }
            }
        }

        return 'mysqldump';
    }
}
