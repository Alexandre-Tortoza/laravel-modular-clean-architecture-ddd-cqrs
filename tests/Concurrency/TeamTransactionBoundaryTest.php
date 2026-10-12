<?php

use App\Modules\Identity\Application\Api\ProfileAccounts;
use App\Modules\Identity\Application\Api\TeamSelectionAccounts;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Teams\Application\Commands\CreateTeam;
use App\Modules\Teams\Application\Commands\CreateTeamHandler;
use App\Modules\Teams\Application\Commands\CreateTeamInvitation;
use App\Modules\Teams\Application\Commands\CreateTeamInvitationHandler;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

pest()->extend(TestCase::class);

test('Teams and Identity writes roll back together in the caller transaction', function () {
    Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    $user = User::factory()->create();

    try {
        DB::transaction(function () use ($user): void {
            app(CreateTeamHandler::class)->handle(new CreateTeam($user->id, 'Rollback Team'));
            expect($user->fresh()->current_team_id)->not->toBeNull();
            throw new RuntimeException('Abort the enclosing operation');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Abort the enclosing operation');
    }

    expect($user->fresh()->current_team_id)->toBeNull()
        ->and(Team::query()->where('name', 'Rollback Team')->exists())->toBeFalse();
});

test('Identity user locks require an active transaction', function () {
    Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    $user = User::factory()->create();

    expect(fn () => app(TeamSelectionAccounts::class)->lockMany([$user->id]))
        ->toThrow(LogicException::class);
    expect(fn () => app(ProfileAccounts::class)->lockOwnProfile($user->id))
        ->toThrow(LogicException::class);
});

test('an invitation waits for the external commit and is discarded on external rollback', function () {
    Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    $delivered = [];
    Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$delivered): void {
        $delivered[] = $event;
    });
    $owner = User::factory()->create();
    $team = app(CreateTeamHandler::class)->handle(new CreateTeam($owner->id, 'Invitation Team'));

    DB::beginTransaction();
    app(CreateTeamInvitationHandler::class)->handle(new CreateTeamInvitation($team->id, $owner->id, 'rollback@example.com', TeamRole::Member));
    expect($delivered)->toBe([]);
    DB::rollBack();
    expect($delivered)->toBe([]);

    DB::beginTransaction();
    app(CreateTeamInvitationHandler::class)->handle(new CreateTeamInvitation($team->id, $owner->id, 'commit@example.com', TeamRole::Member));
    expect($delivered)->toBe([]);
    DB::commit();
    expect($delivered)->toHaveCount(1);
});
