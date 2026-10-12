<?php

use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Profile\Application\Commands\UpdateProfile;
use App\Modules\Profile\Application\Commands\UpdateProfileHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class);

test('a stale profile update cannot preserve verification for another email', function () {
    if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('This test requires PostgreSQL and pcntl.');
    }

    $user = User::factory()->unverified()->create(['email' => 'original@example.com']);
    $barrier = sys_get_temp_dir().'/profile-lock-'.bin2hex(random_bytes(8));
    mkdir($barrier);

    $childProcessId = pcntl_fork();
    expect($childProcessId)->not->toBe(-1);

    $isChild = $childProcessId === 0;
    $connectionName = $isChild ? 'profile_concurrency_second' : 'profile_concurrency_first';
    $resultFile = $barrier.'/'.($isChild ? 'child-result' : 'parent-result');
    $lockedRead = false;

    try {
        config()->set("database.connections.{$connectionName}", config('database.connections.pgsql'));
        DB::setDefaultConnection($connectionName);
        DB::connection($connectionName)->statement("set lock_timeout = '8s'");

        if ($isChild) {
            $deadline = microtime(true) + 10;

            while (! file_exists($barrier.'/parent-read')) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('The first update did not read the user.');
                }

                usleep(10_000);
            }

            file_put_contents($barrier.'/child-started', 'started');
            app(UpdateProfileHandler::class)->handle(new UpdateProfile($user->id, 'Second User', 'changed@example.com'));
            User::query()->findOrFail($user->id)->markEmailAsVerified();
            file_put_contents($barrier.'/child-completed', 'completed');
        } else {
            $observedRead = false;

            DB::listen(function (QueryExecuted $query) use (&$observedRead, &$lockedRead, $barrier): void {
                if ($observedRead || ! str_contains($query->sql, 'from "users"') || ! str_starts_with(strtolower($query->sql), 'select')) {
                    return;
                }

                $observedRead = true;
                $lockedRead = str_contains(strtolower($query->sql), 'for update');
                file_put_contents($barrier.'/parent-read', 'read');
                $deadline = microtime(true) + 10;

                while (! file_exists($barrier.'/child-started')) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('The second update did not start.');
                    }

                    usleep(10_000);
                }

                $deadline = microtime(true) + 1;

                while (! file_exists($barrier.'/child-completed') && microtime(true) < $deadline) {
                    usleep(10_000);
                }
            });

            app(UpdateProfileHandler::class)->handle(new UpdateProfile($user->id, 'First User', 'original@example.com'));

            if (! $observedRead) {
                throw new RuntimeException('The first update did not read the user.');
            }
        }

        file_put_contents($resultFile, 'ok');
    } catch (Throwable $exception) {
        file_put_contents($resultFile, $exception::class.': '.$exception->getMessage());
    }

    if ($isChild) {
        exit(0);
    }

    pcntl_waitpid($childProcessId, $status);
    DB::setDefaultConnection('pgsql');

    $parentResult = file_get_contents($barrier.'/parent-result');
    $childResult = file_exists($barrier.'/child-result') ? file_get_contents($barrier.'/child-result') : 'Child process exited without a result.';

    foreach (glob($barrier.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($barrier);
    expect($parentResult)->toBe('ok')
        ->and($childResult)->toBe('ok')
        ->and(pcntl_wexitstatus($status))->toBe(0)
        ->and($lockedRead)->toBeTrue();

    $finalUser = $user->fresh();

    expect($finalUser->email)->toBe('changed@example.com')
        ->and($finalUser->email_verified_at)->not->toBeNull();
});
