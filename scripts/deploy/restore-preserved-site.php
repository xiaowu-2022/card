<?php

// Infrastructure restore only. Never replay business operations or rewrite individual balances.
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

umask(0077);
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function restoreRun(array $command, array $environment = [], mixed $input = null): string
{
    $process = new Process($command, base_path(), $environment);
    $process->setTimeout(600);
    if ($input !== null) {
        $process->setInput($input);
    }
    $process->run();
    if (! $process->isSuccessful()) {
        // External errors can contain decrypted data or connection details.
        throw new RuntimeException('Restore step failed: '.basename($command[0]).'. Site remains in maintenance; original target backup is preserved.');
    }

    return $process->getOutput();
}

try {
    $bundle = isset($argv[1]) ? realpath($argv[1]) : false;
    if (! $bundle || str_starts_with($bundle.'/', realpath(base_path()).'/')) {
        throw new RuntimeException('Supply an absolute backup directory outside the website directory.');
    }
    $sums = file($bundle.'/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach (['card_mock.dump', 'private-config-storage.tar.gz', 'card_mock.counts'] as $file) {
        $expected = null;
        foreach ($sums as $line) {
            if (preg_match('/^([a-f0-9]{64})\s+\*?(?:\.\/)?'.preg_quote($file, '/').'$/', $line, $match)) {
                $expected = $match[1];
            }
        }
        if (! $expected || ! is_file($bundle.'/'.$file) || ! hash_equals($expected, hash_file('sha256', $bundle.'/'.$file))) {
            throw new RuntimeException('Missing backup or checksum mismatch: '.$file);
        }
    }
    if (DB::getDriverName() !== 'pgsql') {
        throw new RuntimeException('The configured database must be PostgreSQL.');
    }
    $serverVersion = (int) DB::selectOne('SHOW server_version_num')->server_version_num;
    if ($serverVersion < 180000 || $serverVersion >= 190000) {
        throw new RuntimeException('Use PostgreSQL 18 for the target database.');
    }
    $pg = DB::connection()->getConfig();
    $database = DB::selectOne('SELECT current_database() AS name')->name;
    if ($database !== DB::connection()->getDatabaseName()) {
        throw new RuntimeException('Database configuration and active connection disagree.');
    }
    $environment = [
        'PGHOST' => (string) $pg['host'], 'PGPORT' => (string) $pg['port'],
        'PGDATABASE' => $database, 'PGUSER' => (string) $pg['username'],
        'PGPASSWORD' => (string) ($pg['password'] ?? ''), 'PGSSLMODE' => (string) ($pg['sslmode'] ?? 'prefer'),
        'PGOPTIONS' => '-c lock_timeout=5000',
    ];
    if (! preg_match('/PostgreSQL\) 18\./', restoreRun(['pg_restore', '--version']))) {
        throw new RuntimeException('Install PostgreSQL 18 client tools before restoring this backup.');
    }
    if (! DB::selectOne("SELECT pg_try_advisory_lock(hashtextextended('preserved-site-restore-v1', 0)) AS acquired")->acquired) {
        throw new RuntimeException('Another restore is running for this database. Nothing was overwritten.');
    }
    $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public'");
    // These are the only non-business rows created by the empty migration baseline.
    $baseline = ['migrations' => 59, 'permissions' => 4, 'platform_kyc_settings' => 1, 'promotion_invitation_counter' => 1];
    foreach ($tables as $table) {
        $count = DB::table($table->tablename)->count();
        if ($count > ($baseline[$table->tablename] ?? 0)) {
            throw new RuntimeException('Target contains existing records; nothing was overwritten. This command only restores into an empty migration baseline.');
        }
    }
    $work = $bundle.'/target-before-restore-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
    if (! mkdir($work, 0700)) {
        throw new RuntimeException('Cannot create protected restore workspace.');
    }
    echo 'Target backup: '.$work.PHP_EOL;
    copy(base_path('.env'), $work.'/.env');
    restoreRun(['pg_dump', '--format=custom', '--file='.$work.'/database.dump'], $environment);
    restoreRun(['tar', '-czf', $work.'/storage-app.tar.gz', 'storage/app']);
    mkdir($work.'/source', 0700);
    restoreRun(['tar', '-xzf', $bundle.'/private-config-storage.tar.gz', '-C', $work.'/source', '.env', 'storage/app']);
    $source = Dotenv::parse(file_get_contents($work.'/source/.env'));
    $keys = ['APP_KEY', 'KYC_DATA_ENCRYPTION_KEY', 'KYC_IDENTITY_HASH_KEY', 'WITHDRAWAL_ADDRESS_ENCRYPTION_KEY', 'WITHDRAWAL_ADDRESS_HASH_KEY'];
    foreach ($keys as $key) {
        if (! is_string($source[$key] ?? null) || trim($source[$key]) === '') {
            throw new RuntimeException('Backup is missing a persistent data key: '.$key);
        }
    }
    $values = array_intersect_key($source, array_flip($keys));
    $values['USER_OTP_SECRET'] = $source['USER_OTP_SECRET'] ?? $source['APP_KEY'];
    $values += ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'CARD_PROVIDER_DRIVER' => 'directory'];
    foreach (['PAYMENT_PROVIDER_DRIVER' => 'payment.driver', 'KYC_OCR_DRIVER' => 'kyc.ocr_driver', 'BLOCKCHAIN_GATEWAY_DRIVER' => 'withdrawal.blockchain_driver'] as $key => $config) {
        if (config($config) === 'mock') {
            $values[$key] = 'unavailable';
        }
    }
    $env = file_get_contents(base_path('.env'));
    foreach ($values as $key => $value) {
        $literal = '"'.str_replace(['\\', '"', '$', "\n", "\r"], ['\\\\', '\\"', '\\$', '\\n', '\\r'], $value).'"';
        $pattern = '/^'.preg_quote($key, '/').'=(?:"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[^\r\n]*)/m';
        if (preg_match($pattern, $env)) {
            $env = preg_replace_callback($pattern, fn () => $key.'='.$literal, $env);
        } else {
            $env .= PHP_EOL.$key.'='.$literal.PHP_EOL;
        }
    }
    // Validate serialization privately before touching the target database or configuration.
    $parsed = Dotenv::parse($env);
    foreach ($values as $key => $value) {
        if (($parsed[$key] ?? null) !== $value) {
            throw new RuntimeException('Persistent configuration could not be serialized safely.');
        }
    }
    $app->make(Kernel::class)->call('down');
    $input = fopen($bundle.'/card_mock.dump', 'rb');
    try {
        restoreRun(['pg_restore', '--dbname='.$database, '--clean', '--if-exists', '--no-owner', '--no-privileges', '--single-transaction', '--exit-on-error'], $environment, $input);
    } finally {
        fclose($input);
    }
    $owner = fileowner(storage_path('app'));
    $group = filegroup(storage_path('app'));
    $sourceRoot = $work.'/source/storage/app';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        $relative = substr($file->getPathname(), strlen($sourceRoot) + 1);
        if ($file->isLink()) {
            throw new RuntimeException('Private backup contains a symbolic link; manual review required.');
        }
        $target = storage_path('app/'.$relative);
        if (is_link($target)) {
            throw new RuntimeException('Destination contains a symbolic link; manual review required.');
        }
        if ($file->isDir()) {
            if (! is_dir($target) && ! mkdir($target, 0700, true)) {
                throw new RuntimeException('Cannot restore a storage directory.');
            }
        } elseif (! copy($file->getPathname(), $target)) {
            throw new RuntimeException('Cannot restore a private file.');
        }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            chown($target, $owner);
            chgrp($target, $group);
        }
    }
    $envPath = base_path('.env');
    $envOwner = fileowner($envPath);
    $envGroup = filegroup($envPath);
    $envMode = fileperms($envPath) & 0777;
    $nextEnv = $envPath.'.restore-'.bin2hex(random_bytes(4));
    if (file_put_contents($nextEnv, $env) === false) {
        throw new RuntimeException('Cannot write restored persistent configuration.');
    }
    chmod($nextEnv, $envMode);
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        chown($nextEnv, $envOwner);
        chgrp($nextEnv, $envGroup);
    }
    rename($nextEnv, $envPath);
    $runtime = $values + ['DB_DATABASE' => $database];
    restoreRun([PHP_BINARY, 'artisan', 'config:clear'], $runtime);
    restoreRun([PHP_BINARY, 'artisan', 'migrate', '--force'], $runtime);
    restoreRun([PHP_BINARY, 'artisan', 'ledger:reconcile'], $runtime);
    $expectedCounts = [];
    foreach (file($bundle.'/card_mock.counts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$name, $count] = explode('|', $line, 2);
        $expectedCounts[$name] = (int) $count;
    }
    foreach (['tenants' => 'tenants', 'users' => 'users', 'cards' => 'user_cards', 'ledger_entries' => 'ledger_entries', 'ledger_postings' => 'ledger_postings'] as $name => $table) {
        if (! array_key_exists($name, $expectedCounts) || DB::table($table)->count() !== $expectedCounts[$name]) {
            throw new RuntimeException('Restored counts do not match backup. Site remains in maintenance.');
        }
    }
    echo 'RESTORED: accounts, cards, balances, history, private files and data keys preserved in the configured database.'.PHP_EOL;
    echo 'Website remains in maintenance. Complete domain/TLS and runtime checks, then run php artisan up. No queue or financial operation was started.'.PHP_EOL;
} catch (Throwable $error) {
    // Only locally authored RuntimeException messages are safe; do not dump DB/process exceptions.
    fwrite(STDERR, get_class($error) === RuntimeException::class ? $error->getMessage().PHP_EOL : 'Restore failed. Site state must be reviewed privately; no sensitive exception was printed.'.PHP_EOL);
    exit(1);
}
