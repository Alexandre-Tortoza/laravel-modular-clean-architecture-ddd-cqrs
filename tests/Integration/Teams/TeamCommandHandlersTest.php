<?php

use App\Models\User;
use App\Modules\Teams\Application\Commands\AcceptTeamInvitation;
use App\Modules\Teams\Application\Commands\CreateTeam;
use App\Modules\Teams\Application\Commands\CreateTeamHandler;
use App\Modules\Teams\Application\Commands\DeleteTeam;
use App\Modules\Teams\Application\Commands\LeaveTeam;
use App\Modules\Teams\Application\Commands\RemoveTeamMember;
use App\Modules\Teams\Application\Commands\TeamInvitationHandler;
use App\Modules\Teams\Application\Commands\TeamLifecycleHandler;
use App\Modules\Teams\Application\Commands\UpdateTeam;
use App\Modules\Teams\Application\Commands\UpdateTeamMemberRole;
use App\Modules\Teams\Application\Contracts\ManagesTeams;
use App\Modules\Teams\Application\Exceptions\TeamInvitationResponseRejected;
use App\Modules\Teams\Application\Exceptions\TeamOperationRejected;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

test('creating a team selects it without changing URL defaults in the write layer', function () {
    $user = User::factory()->create();
    $previousTeamSlug = $user->currentTeam->slug;
    URL::defaults(['current_team' => $previousTeamSlug]);

    $createdTeam = app(CreateTeamHandler::class)->handle(new CreateTeam($user->id, 'New Team'));

    expect($user->fresh()->current_team_id)->toBe($createdTeam->id)
        ->and(URL::getDefaultParameters()['current_team'])->toBe($previousTeamSlug);
});

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

    expect(fn () => app(TeamLifecycleHandler::class)->removeMember(new RemoveTeamMember($owner->id, $team->id, $owner->id)))
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

test('direct team updates reject an executor without permission', function () {
    $member = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Original Name']);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    expect(fn () => app(TeamLifecycleHandler::class)->update(new UpdateTeam($member->id, $team->id, 'Changed Name')))
        ->toThrow(TeamOperationRejected::class);

    expect($team->fresh()->name)->toBe('Original Name');
});

test('direct membership changes reject an executor without permission', function (string $action) {
    $actor = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($actor, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $handler = app(TeamLifecycleHandler::class);

    $operation = match ($action) {
        'update' => fn () => $handler->updateMemberRole(new UpdateTeamMemberRole($actor->id, $team->id, $member->id, TeamRole::Admin)),
        'remove' => fn () => $handler->removeMember(new RemoveTeamMember($actor->id, $team->id, $member->id)),
    };

    expect($operation)->toThrow(TeamOperationRejected::class);

    expect($member->fresh()->belongsToTeam($team))->toBeTrue()
        ->and($team->members()->whereKey($member->id)->first()->pivot->role)->toBe(TeamRole::Member);
})->with(['update', 'remove']);

test('direct role changes cannot demote the current team owner', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    expect(fn () => app(TeamLifecycleHandler::class)->updateMemberRole(new UpdateTeamMemberRole($owner->id, $team->id, $owner->id, TeamRole::Member)))
        ->toThrow(TeamOperationRejected::class);

    expect($team->members()->whereKey($owner->id)->first()->pivot->role)->toBe(TeamRole::Owner);
});

test('team and several user locks follow ascending ids with an intermediate actor', function () {
    $lowerIdMember = User::factory()->create();
    $actor = User::factory()->create();
    $higherIdMember = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($lowerIdMember, ['role' => TeamRole::Member->value]);
    $team->members()->attach($actor, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($higherIdMember, ['role' => TeamRole::Member->value]);
    $lowerIdMember->update(['current_team_id' => $team->id]);
    $higherIdMember->update(['current_team_id' => $team->id]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(ManagesTeams::class)->deletionForUpdate($team->id, $actor->id);

    $locks = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), ' from "teams" ') || str_contains(strtolower($query['query']), ' from "users" '))
        ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), ' limit 1'))
        ->values();

    expect($locks->pluck('query')->first())->toContain('teams')
        ->and($locks->slice(1, 3)->pluck('bindings')->map(fn (array $bindings): int => (int) $bindings[0])->all())
        ->toBe([$lowerIdMember->id, $actor->id, $higherIdMember->id]);
});
