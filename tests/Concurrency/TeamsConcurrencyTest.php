<?php

use App\Modules\Identity\Application\Api\TeamSelectionAccounts;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Profile\Application\Commands\DeleteProfile;
use App\Modules\Profile\Application\Commands\DeleteProfileHandler;
use App\Modules\Profile\Domain\Exceptions\ProfileDeletionRejected;
use App\Modules\Teams\Application\Api\TeamContext;
use App\Modules\Teams\Application\Commands\AcceptTeamInvitation;
use App\Modules\Teams\Application\Commands\AcceptTeamInvitationHandler;
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
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\UserWithPersonalTeam;
use Tests\TestCase;

pest()->extend(TestCase::class);

/** @param Closure(): bool $condition */
function waitForTeamRace(string $barrier, Closure $condition, string $message): void
{
    $deadline = microtime(true) + 10;

    while (! $condition()) {
        foreach (glob($barrier.'/*.error') ?: [] as $file) {
            throw new RuntimeException((string) file_get_contents($file));
        }

        if (microtime(true) >= $deadline) {
            throw new RuntimeException($message);
        }

        usleep(10_000);
    }
}

function teamRaceIsBlocked(string $barrier, string $waiting, string $holding): bool
{
    if (! file_exists($barrier.'/'.$waiting.'.pid') || ! file_exists($barrier.'/'.$holding.'.pid')) {
        return false;
    }

    return (bool) DB::selectOne('select ?::integer = any(pg_blocking_pids(?::integer)) as blocked', [
        (int) file_get_contents($barrier.'/'.$holding.'.pid'),
        (int) file_get_contents($barrier.'/'.$waiting.'.pid'),
    ])->blocked;
}

/**
 * @param  array<string, Closure(string): void>  $workers
 * @param  Closure(string): void  $coordinate
 * @return array<string, string>
 */
function runTeamRace(array $workers, Closure $coordinate): array
{
    $barrier = sys_get_temp_dir().'/teams-race-'.bin2hex(random_bytes(8));
    mkdir($barrier);
    $originalConnection = DB::getDefaultConnection();
    $processes = [];
    $results = [];

    foreach (DB::getConnections() as $connection) {
        $connection->disconnect();
    }

    try {
        foreach ($workers as $name => $worker) {
            $processId = pcntl_fork();

            if ($processId === -1) {
                throw new RuntimeException('Could not fork a race worker.');
            }

            if ($processId === 0) {
                try {
                    $connectionName = 'team_race_'.$name;
                    config()->set('database.connections.'.$connectionName, config('database.connections.pgsql'));
                    DB::setDefaultConnection($connectionName);
                    DB::connection()->statement("set lock_timeout = '8s'");
                    file_put_contents($barrier.'/'.$name.'.pid', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
                    $worker($barrier);
                    file_put_contents($barrier.'/'.$name.'.result', 'ok');
                    DB::disconnect();
                    exit(0);
                } catch (Throwable $exception) {
                    file_put_contents($barrier.'/'.$name.'.error', $exception::class.': '.$exception->getMessage());
                    exit(1);
                }
            }

            $processes[$name] = $processId;
        }

        $coordinate($barrier);

        foreach ($processes as $name => $processId) {
            $status = 0;
            waitForTeamRace($barrier, function () use ($processId, &$status): bool {
                return pcntl_waitpid($processId, $status, WNOHANG) === $processId;
            }, 'A race worker did not finish.');
            unset($processes[$name]);

            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                throw new RuntimeException(file_exists($barrier.'/'.$name.'.error')
                    ? (string) file_get_contents($barrier.'/'.$name.'.error') : 'A race worker exited abnormally.');
            }

            $results[$name] = (string) file_get_contents($barrier.'/'.$name.'.result');
        }

        return $results;
    } finally {
        foreach ($processes as $processId) {
            if (pcntl_waitpid($processId, $status, WNOHANG) === 0) {
                posix_kill($processId, SIGTERM);
                pcntl_waitpid($processId, $status);
            }
        }

        DB::setDefaultConnection($originalConnection);

        foreach (glob($barrier.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($barrier);
    }
}

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

    $delete = function (int $actorId, int $teamId, bool $hold): Closure {
        return function (string $barrier) use ($actorId, $teamId, $hold): void {
            $attempts = 0;
            Event::listen(TransactionBeginning::class, function () use (&$attempts): void {
                $attempts++;
            });

            if ($hold) {
                DB::listen(function (QueryExecuted $query) use ($barrier): void {
                    if (! str_contains($query->sql, '"teams"') || ! str_contains(strtolower($query->sql), 'for update')) {
                        return;
                    }

                    file_put_contents($barrier.'/first-locked', 'locked');
                    waitForTeamRace($barrier, fn (): bool => file_exists($barrier.'/release'), 'The first deletion was not released.');
                });
            } else {
                waitForTeamRace($barrier, fn (): bool => file_exists($barrier.'/first-locked'), 'The first deletion did not lock its teams.');
            }

            app(DeleteTeamHandler::class)->handle(new DeleteTeam($actorId, $teamId));
            expect($attempts)->toBe(1);
        };
    };

    $results = runTeamRace([
        'first' => $delete($firstUser->id, $firstTeam->id, true),
        'second' => $delete($secondUser->id, $secondTeam->id, false),
    ], function (string $barrier): void {
        waitForTeamRace($barrier, fn (): bool => teamRaceIsBlocked($barrier, 'second', 'first'), 'The overlapping team locks did not serialize the deletions.');
        file_put_contents($barrier.'/release', 'continue');
    });

    expect($results)->toBe(['first' => 'ok', 'second' => 'ok']);
    expect($firstUser->fresh()->current_team_id)->toBe($firstPersonalTeamId)
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
    $results = runTeamRace([
        'first' => function (string $barrier) use ($fallbackTeam, $fallbackOwner): void {
            $observedSnapshot = false;
            DB::listen(function (QueryExecuted $query) use ($barrier, $fallbackTeam, &$observedSnapshot): void {
                if ($observedSnapshot || ! str_contains($query->sql, '"users"')
                    || ! str_contains($query->sql, '"current_team_id"')
                    || ! in_array($fallbackTeam->id, $query->bindings, true)) {
                    return;
                }

                $observedSnapshot = true;
                file_put_contents($barrier.'/fallback-snapshot', 'snapshot');
                waitForTeamRace($barrier, fn (): bool => file_exists($barrier.'/release'), 'The fallback deletion was not released.');
            });

            app(DeleteTeamHandler::class)->handle(new DeleteTeam($fallbackOwner->id, $fallbackTeam->id));
            expect($observedSnapshot)->toBeTrue();
        },
        'second' => function (string $barrier) use ($user, $selectedTeam): void {
            waitForTeamRace($barrier, fn (): bool => file_exists($barrier.'/fallback-snapshot'), 'The fallback deletion did not take its selection snapshot.');
            app(DeleteTeamHandler::class)->handle(new DeleteTeam($user->id, $selectedTeam->id));
        },
    ], function (string $barrier): void {
        waitForTeamRace($barrier, fn (): bool => file_exists($barrier.'/second.result')
            || teamRaceIsBlocked($barrier, 'second', 'first'), 'The fallback selector neither completed nor reached its team lock.');
        file_put_contents($barrier.'/release', 'continue');
    });

    expect($results)->toBe(['first' => 'ok', 'second' => 'ok']);
    expect($user->fresh()->current_team_id)->toBeNull();
    $this->assertSoftDeleted('teams', ['id' => $fallbackTeam->id]);
    $this->assertSoftDeleted('teams', ['id' => $selectedTeam->id]);
});

test('account deletion rejects memberships added after its team snapshot', function (string $operation): void {
    if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('This test requires PostgreSQL and pcntl.');
    }

    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $teamName = 'Concurrent account team '.$user->id;
    $invitation = null;

    if ($operation === 'accept') {
        $team = app(CreateTeamHandler::class)->handle(new CreateTeam($otherUser->id, $teamName));
        $invitation = TeamInvitation::factory()->create([
            'team_id' => $team->id, 'email' => $user->email, 'invited_by' => $otherUser->id,
        ]);
    }

    $results = runTeamRace([
        'first' => function (string $barrier) use ($user): void {
            $observedSnapshot = false;
            DB::listen(function (QueryExecuted $query) use ($barrier, &$observedSnapshot): void {
                if ($observedSnapshot || ! str_contains($query->sql, '"teams"')
                    || ! str_contains(strtolower($query->sql), 'for update')) {
                    return;
                }

                $observedSnapshot = true;
                file_put_contents($barrier.'/account-snapshot', 'snapshot');
                waitForTeamRace($barrier, fn (): bool => file_exists($barrier.'/release'), 'The account deletion was not released.');
            });

            expect(fn () => app(DeleteProfileHandler::class)->handle(new DeleteProfile($user->id)))
                ->toThrow(ProfileDeletionRejected::class, 'The account teams changed. Please retry deleting your account.');
            expect($observedSnapshot)->toBeTrue();
        },
        'second' => function (string $barrier) use ($operation, $user, $otherUser, $teamName, $invitation): void {
            waitForTeamRace($barrier, fn (): bool => file_exists($barrier.'/account-snapshot'), 'The account deletion did not take its team snapshot.');

            if ($operation === 'create') {
                $team = app(CreateTeamHandler::class)->handle(new CreateTeam($user->id, $teamName));
                Team::query()->findOrFail($team->id)->memberships()->create(['user_id' => $otherUser->id, 'role' => TeamRole::Member]);
            } else {
                app(AcceptTeamInvitationHandler::class)->handle(new AcceptTeamInvitation($user->id, $invitation->id));
            }
        },
    ], function (string $barrier): void {
        waitForTeamRace($barrier, fn (): bool => file_exists($barrier.'/second.result'), 'The concurrent membership operation did not commit.');
        file_put_contents($barrier.'/release', 'continue');
    });

    expect($results)->toBe(['first' => 'ok', 'second' => 'ok']);
    $team = Team::query()->where('name', $teamName)->firstOrFail();
    $this->assertModelExists($user);
    expect($user->fresh()->current_team_id)->toBe($team->id);
    expect(app(TeamContext::class)->roleFor($user->id, $team->id))->toBe($operation === 'create' ? TeamRole::Owner : TeamRole::Member);
    expect(app(TeamContext::class)->roleFor($otherUser->id, $team->id))->toBe($operation === 'create' ? TeamRole::Member : TeamRole::Owner);

    if ($invitation !== null) {
        expect($invitation->fresh()->accepted_at)->not->toBeNull();
    }
})->with(['create', 'accept']);
