<?php

use App\Models\User;
use App\Modules\Teams\Application\Commands\AcceptTeamInvitation;
use App\Modules\Teams\Application\Commands\DeleteTeam;
use App\Modules\Teams\Application\Commands\LeaveTeam;
use App\Modules\Teams\Application\Commands\RemoveTeamMember;
use App\Modules\Teams\Application\Commands\TeamInvitationHandler;
use App\Modules\Teams\Application\Commands\TeamLifecycleHandler;
use App\Modules\Teams\Application\Contracts\ManagesTeams;
use App\Modules\Teams\Application\Exceptions\TeamInvitationResponseRejected;
use App\Modules\Teams\Application\Exceptions\TeamOperationRejected;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;
use Illuminate\Support\Facades\DB;

test('accept handler rejects invitations sent to another email address', function () {
    $owner = User::factory()->create();
    $recipient = User::factory()->create(['email' => 'recipient@example.com']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'someone-else@example.com',
        'invited_by' => $owner->id,
    ]);

    expect(fn () => app(TeamInvitationHandler::class)->accept(new AcceptTeamInvitation($recipient->id, $invitation->id)))
        ->toThrow(TeamInvitationResponseRejected::class);

    expect($recipient->fresh()->belongsToTeam($team))->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

test('accept handler rejects accepted and expired invitations', function (string $state): void {
    $owner = User::factory()->create();
    $recipient = User::factory()->create(['email' => 'recipient@example.com']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $invitation = TeamInvitation::factory()->{$state}()->create([
        'team_id' => $team->id,
        'email' => $recipient->email,
        'invited_by' => $owner->id,
    ]);

    expect(fn () => app(TeamInvitationHandler::class)->accept(new AcceptTeamInvitation($recipient->id, $invitation->id)))
        ->toThrow(TeamInvitationResponseRejected::class);

    expect($recipient->fresh()->belongsToTeam($team))->toBeFalse();
})->with(['accepted', 'expired']);

test('leave handler protects personal teams and team owners', function (bool $personal): void {
    $user = User::factory()->create();
    $team = $personal ? $user->personalTeam() : Team::factory()->create();

    if (! $personal) {
        $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    }

    expect(fn () => app(TeamLifecycleHandler::class)->leave(new LeaveTeam($user->id, $team->id)))
        ->toThrow(TeamOperationRejected::class);

    expect($user->fresh()->belongsToTeam($team))->toBeTrue();
})->with([true, false]);

test('remove handler protects team owners', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    expect(fn () => app(TeamLifecycleHandler::class)->removeMember(new RemoveTeamMember($team->id, $owner->id)))
        ->toThrow(TeamOperationRejected::class);

    expect($owner->fresh()->belongsToTeam($team))->toBeTrue();
});

test('delete handler protects personal teams', function () {
    $user = User::factory()->create();
    $team = $user->personalTeam();

    expect(fn () => app(TeamLifecycleHandler::class)->delete(new DeleteTeam($user->id, $team->id)))
        ->toThrow(TeamOperationRejected::class);

    $this->assertNotSoftDeleted('teams', ['id' => $team->id]);
});

test('team and user locks are acquired in canonical order', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(ManagesTeams::class)->membershipForUpdate($team->id, $user->id);

    $queries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_starts_with(strtolower($query), 'select'))
        ->values();

    expect($queries[0])->toContain('teams')
        ->and($queries[1])->toContain('users');
});
