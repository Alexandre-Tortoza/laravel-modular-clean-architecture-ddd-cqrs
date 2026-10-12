<?php

use App\Modules\Identity\Application\Api\TeamSelectionAccounts;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Teams\Application\Api\TeamContext;
use App\Modules\Teams\Application\Commands\CreateTeam;
use App\Modules\Teams\Application\Commands\CreateTeamHandler;
use App\Modules\Teams\Application\Commands\CreateTeamInvitation;
use App\Modules\Teams\Application\Commands\CreateTeamInvitationHandler;
use App\Modules\Teams\Application\Commands\DeleteTeam;
use App\Modules\Teams\Application\Commands\DeleteTeamHandler;
use App\Modules\Teams\Application\Commands\UpdateTeamMemberRole;
use App\Modules\Teams\Application\Commands\UpdateTeamMemberRoleHandler;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Domain\Exceptions\TeamInvitationCreationRejected;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\UserWithPersonalTeam;
use Tests\TestCase;

pest()->extend(TestCase::class);

test('opposing member changes complete across two PostgreSQL connections', function () {
    if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('This test requires PostgreSQL and pcntl.');
    }

    $firstUser = UserWithPersonalTeam::create();
    $secondUser = UserWithPersonalTeam::create();
    $firstTeam = Team::factory()->create();
    $secondTeam = Team::factory()->create();

    $firstTeam->memberships()->create(['user_id' => $firstUser->id, 'role' => TeamRole::Owner]);
    $firstTeam->memberships()->create(['user_id' => $secondUser->id, 'role' => TeamRole::Member]);
    $secondTeam->memberships()->create(['user_id' => $secondUser->id, 'role' => TeamRole::Owner]);
    $secondTeam->memberships()->create(['user_id' => $firstUser->id, 'role' => TeamRole::Member]);

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

        app(UpdateTeamMemberRoleHandler::class)->handle(new UpdateTeamMemberRole(
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
        ->and(app(TeamContext::class)->roleFor($firstUser->id, $secondTeam->id))->toBe(TeamRole::Admin)
        ->and(app(TeamContext::class)->roleFor($secondUser->id, $firstTeam->id))->toBe(TeamRole::Admin);
});

test('opposing team deletions complete without a user lock deadlock', function () {
    if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('This test requires PostgreSQL and pcntl.');
    }

    $firstUser = UserWithPersonalTeam::create();
    $secondUser = UserWithPersonalTeam::create();
    $firstPersonalTeamId = UserWithPersonalTeam::personalTeam($firstUser)->id;
    $secondPersonalTeamId = UserWithPersonalTeam::personalTeam($secondUser)->id;
    $firstTeam = Team::factory()->create();
    $secondTeam = Team::factory()->create();

    $firstTeam->memberships()->create(['user_id' => $firstUser->id, 'role' => TeamRole::Owner]);
    $firstTeam->memberships()->create(['user_id' => $secondUser->id, 'role' => TeamRole::Member]);
    $secondTeam->memberships()->create(['user_id' => $secondUser->id, 'role' => TeamRole::Owner]);
    $secondTeam->memberships()->create(['user_id' => $firstUser->id, 'role' => TeamRole::Member]);
    DB::transaction(function () use ($firstUser, $secondUser, $firstTeam, $secondTeam): void {
        app(TeamSelectionAccounts::class)->select($firstUser->id, $secondTeam->id);
        app(TeamSelectionAccounts::class)->select($secondUser->id, $firstTeam->id);
    });

    $barrier = sys_get_temp_dir().'/teams-deletion-locks-'.bin2hex(random_bytes(8));
    mkdir($barrier);

    $childProcessId = pcntl_fork();
    expect($childProcessId)->not->toBe(-1);

    $isChild = $childProcessId === 0;
    $connectionName = $isChild ? 'teams_deletion_second' : 'teams_deletion_first';
    $ownMarker = $barrier.'/'.($isChild ? 'second' : 'first');
    $peerMarker = $barrier.'/'.($isChild ? 'first' : 'second');
    $actorId = $isChild ? $secondUser->id : $firstUser->id;
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

        app(DeleteTeamHandler::class)->handle(new DeleteTeam($actorId, $teamId));

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
        ->and($firstUser->fresh()->current_team_id)->toBe($firstPersonalTeamId)
        ->and($secondUser->fresh()->current_team_id)->toBe($secondPersonalTeamId);

    $this->assertSoftDeleted('teams', ['id' => $firstTeam->id]);
    $this->assertSoftDeleted('teams', ['id' => $secondTeam->id]);
});

test('concurrent invitations for the same email create only one pending invitation', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('This test requires PostgreSQL and pcntl.');
    }

    $owner = UserWithPersonalTeam::create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $barrier = sys_get_temp_dir().'/teams-invitation-locks-'.bin2hex(random_bytes(8));
    mkdir($barrier);

    $childProcessId = pcntl_fork();
    expect($childProcessId)->not->toBe(-1);
    $isChild = $childProcessId === 0;
    $connectionName = $isChild ? 'teams_invitation_second' : 'teams_invitation_first';
    $ownMarker = $barrier.'/'.($isChild ? 'second' : 'first');
    $peerMarker = $barrier.'/'.($isChild ? 'first' : 'second');
    $resultFile = $barrier.'/'.($isChild ? 'child-result' : 'parent-result');

    try {
        config()->set("database.connections.{$connectionName}", config('database.connections.pgsql'));
        DB::setDefaultConnection($connectionName);
        DB::connection($connectionName)->statement("set lock_timeout = '8s'");
        Notification::fake();
        file_put_contents($ownMarker, 'ready');
        $deadline = microtime(true) + 10;

        while (! file_exists($peerMarker)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The other connection did not reach the invitation barrier.');
            }

            usleep(10_000);
        }

        app(CreateTeamInvitationHandler::class)->handle(
            new CreateTeamInvitation($team->id, $owner->id, 'race@example.com', TeamRole::Member),
        );
        file_put_contents($resultFile, 'created');
    } catch (TeamInvitationCreationRejected $exception) {
        file_put_contents($resultFile, 'duplicate');
    } catch (Throwable $exception) {
        file_put_contents($resultFile, $exception::class.': '.$exception->getMessage());
    }

    if ($isChild) {
        exit(0);
    }

    pcntl_waitpid($childProcessId, $status);
    DB::setDefaultConnection('pgsql');
    $results = [
        file_get_contents($barrier.'/parent-result'),
        file_exists($barrier.'/child-result') ? file_get_contents($barrier.'/child-result') : 'Child process exited without a result.',
    ];

    foreach (glob($barrier.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($barrier);
    sort($results);

    expect($results)->toBe(['created', 'duplicate'])
        ->and(pcntl_wexitstatus($status))->toBe(0)
        ->and(TeamInvitation::query()
            ->where('team_id', $team->id)->where('email', 'race@example.com')->count())->toBe(1);
});

test('deleting a fallback team cannot leave a current selection pointing to its soft-deleted row', function (): void {
    if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('This test requires PostgreSQL and pcntl.');
    }

    $user = User::factory()->create();
    $fallbackOwner = User::factory()->create();
    $fallbackTeam = Team::factory()->create(['name' => 'Fallback Team']);
    $fallbackTeam->memberships()->create(['user_id' => $fallbackOwner->id, 'role' => TeamRole::Owner]);
    $fallbackTeam->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);
    $selectedTeam = app(CreateTeamHandler::class)->handle(new CreateTeam($user->id, 'Selected Team'));
    $barrier = sys_get_temp_dir().'/teams-fallback-locks-'.bin2hex(random_bytes(8));
    mkdir($barrier);

    $deleteFallbackProcessId = pcntl_fork();
    expect($deleteFallbackProcessId)->not->toBe(-1);

    if ($deleteFallbackProcessId === 0) {
        try {
            config()->set('database.connections.teams_fallback_delete', config('database.connections.pgsql'));
            DB::setDefaultConnection('teams_fallback_delete');
            DB::connection()->statement("set lock_timeout = '8s'");
            DB::listen(function (QueryExecuted $query) use ($barrier, $fallbackTeam): void {
                if (! str_contains($query->sql, '"users"')
                    || ! str_contains($query->sql, '"current_team_id"')
                    || ! in_array($fallbackTeam->id, $query->bindings, true)) {
                    return;
                }

                file_put_contents($barrier.'/fallback-snapshot');
                $deadline = microtime(true) + 10;

                while (! file_exists($barrier.'/continue-fallback-delete')) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('The fallback deletion was not released.');
                    }

                    usleep(10_000);
                }
            });

            app(DeleteTeamHandler::class)->handle(new DeleteTeam($fallbackOwner->id, $fallbackTeam->id));
            file_put_contents($barrier.'/fallback-delete-result', 'ok');
        } catch (Throwable $exception) {
            file_put_contents($barrier.'/fallback-delete-result', $exception::class.': '.$exception->getMessage());
        }

        exit(0);
    }

    $snapshotDeadline = microtime(true) + 10;

    while (! file_exists($barrier.'/fallback-snapshot')) {
        if (microtime(true) >= $snapshotDeadline) {
            file_put_contents($barrier.'/continue-fallback-delete', 'continue');
            pcntl_waitpid($deleteFallbackProcessId, $status);
            throw new RuntimeException('The fallback deletion did not take its selection snapshot.');
        }

        usleep(10_000);
    }

    $selectFallbackProcessId = pcntl_fork();
    expect($selectFallbackProcessId)->not->toBe(-1);

    if ($selectFallbackProcessId === 0) {
        try {
            config()->set('database.connections.teams_fallback_select', config('database.connections.pgsql'));
            DB::setDefaultConnection('teams_fallback_select');
            DB::connection()->statement("set lock_timeout = '8s'");
            app(DeleteTeamHandler::class)->handle(new DeleteTeam($user->id, $selectedTeam->id));
            file_put_contents($barrier.'/select-fallback-result', 'ok');
        } catch (Throwable $exception) {
            file_put_contents($barrier.'/select-fallback-result', $exception::class.': '.$exception->getMessage());
        }

        exit(0);
    }

    $selectionDeadline = microtime(true) + 2;

    while (! file_exists($barrier.'/select-fallback-result') && microtime(true) < $selectionDeadline) {
        usleep(10_000);
    }

    file_put_contents($barrier.'/continue-fallback-delete');
    pcntl_waitpid($deleteFallbackProcessId, $fallbackStatus);
    pcntl_waitpid($selectFallbackProcessId, $selectionStatus);
    DB::setDefaultConnection('pgsql');
    $fallbackResult = file_get_contents($barrier.'/fallback-delete-result');
    $selectionResult = file_get_contents($barrier.'/select-fallback-result');

    foreach (glob($barrier.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($barrier);

    expect($fallbackResult)->toBe('ok')
        ->and($selectionResult)->toBe('ok')
        ->and(pcntl_wexitstatus($fallbackStatus))->toBe(0)
        ->and(pcntl_wexitstatus($selectionStatus))->toBe(0)
        ->and($user->fresh()->current_team_id)->toBeNull();
    $this->assertSoftDeleted('teams', ['id' => $fallbackTeam->id]);
    $this->assertSoftDeleted('teams', ['id' => $selectedTeam->id]);
});
