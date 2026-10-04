<?php

use App\Support\Errors\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

function racePromotionAdjustments(array $operations): array
{
    if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'card_ui_test' || DB::transactionLevel() !== 1) {
        throw new RuntimeException('Concurrent fixtures require isolated card_ui_test.');
    }
    DB::commit();
    RefreshDatabaseState::$migrated = false;
    DB::disconnect();
    $directory = sys_get_temp_dir().'/asset-race-'.Str::uuid();
    mkdir($directory, 0700);
    $children = [];
    foreach ($operations as $index => $operation) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to start isolated worker');
        }
        if ($pid === 0) {
            DB::purge();
            while (! is_file($directory.'/go')) {
                usleep(1000);
            }
            try {
                $operation();
                $result = 'completed';
            } catch (DomainException|HttpException $e) {
                $result = 'rejected';
            } catch (Throwable $e) {
                $result = 'error:'.get_class($e);
            }
            file_put_contents($directory.'/'.$index, $result);
            exit(0);
        }
        $children[] = $pid;
    }
    touch($directory.'/go');
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        if (pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException('Worker failed');
        }
    }
    DB::reconnect();
    DB::beginTransaction();
    $results = [];
    foreach (array_keys($operations) as $index) {
        $results[] = file_get_contents($directory.'/'.$index);
        unlink($directory.'/'.$index);
    }
    unlink($directory.'/go');
    rmdir($directory);

    return $results;
}
