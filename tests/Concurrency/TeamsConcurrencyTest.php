<?php

use App\Models\User;
use App\Modules\Teams\Application\Commands\TeamLifecycleHandler;
use App\Modules\Teams\Application\Commands\UpdateTeamMemberRole;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class);

test('opposing member changes complete across two PostgreSQL connections', function () {
    if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('This test requires PostgreSQL and pcntl.');
    }

    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $firstTeam = Team::factory()->create();
    $secondTeam = Team::factory()->create();

    $firstTeam->members()->attach($firstUser, ['role' => TeamRole::Owner->value]);
    $firstTeam->members()->attach($secondUser, ['role' => TeamRole::Member->value]);
    $secondTeam->members()->attach($secondUser, ['role' => TeamRole::Owner->value]);
    $secondTeam->members()->attach($firstUser, ['role' => TeamRole::Member->value]);

    $barrier = sys_get_temp_dir().'/teams-locks-'.bin2hex(random_bytes(8));
    mkdir($barrier);

    $childProcessId = pcntl_fork();
    expect($childProcessId)->not->toBe(-1);

    $isChild = $childProcessId === 0;
    $connectionName = $isChild ? 'teams_concurrency_second' : 'teams_concurrency_first';
    $ownMarker = $barrier.'/'.($isChild ? 'second' : 'first');
    $peerMarker = $barrier.'/'.($isChild ? 'first' : 'second');
    $actorId = $isChild ? $secondUser->id : $firstUser->id;
    $memberId = $isChild ? $firstUser->id : $secondUser->id;
    $teamId = $isChild ? $secondTeam->id : $firstTeam->id;

    try {
        config()->set("database.connections.{$connectionName}", config('database.connections.pgsql'));
        DB::setDefaultConnection($connectionName);
        DB::connection($connectionName)->statement("set lock_timeout = '8s'");

        $teamLockCount = 0;
        DB::listen(function (QueryExecuted $query) use (&$teamLockCount, $ownMarker, $peerMarker): void {
            if (! str_contains($query->sql, '"teams"') || ! str_contains(strtolower($query->sql), 'for update')) {
                return;
            }

            $teamLockCount++;

            if ($teamLockCount > 1) {
                return;
            }

            file_put_contents($ownMarker, 'locked');

            $deadline = microtime(true) + 10;

            while (! file_exists($peerMarker)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('The other connection did not acquire its team lock.');
                }

                usleep(10_000);
            }
        });

        app(TeamLifecycleHandler::class)->updateMemberRole(new UpdateTeamMemberRole(
            actorId: $actorId,
            teamId: $teamId,
            userId: $memberId,
            role: TeamRole::Admin,
        ));

        if ($teamLockCount !== 1) {
            throw new RuntimeException("Expected one team lock without a retry; acquired {$teamLockCount}.");
        }

        file_put_contents($barrier.'/'.($isChild ? 'child-result' : 'parent-result'), 'ok');
    } catch (Throwable $exception) {
        file_put_contents($barrier.'/'.($isChild ? 'child-result' : 'parent-result'), $exception::class.': '.$exception->getMessage());
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
        ->and($firstUser->fresh()->teamRole($secondTeam))->toBe(TeamRole::Admin)
        ->and($secondUser->fresh()->teamRole($firstTeam))->toBe(TeamRole::Admin);
});
