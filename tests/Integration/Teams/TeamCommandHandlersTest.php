<?php

use App\Modules\Identity\Application\Api\TeamSelectionAccounts;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Teams\Application\Api\TeamContext;
use App\Modules\Teams\Application\Commands\AcceptTeamInvitation;
use App\Modules\Teams\Application\Commands\AcceptTeamInvitationHandler;
use App\Modules\Teams\Application\Commands\CreateTeam;
use App\Modules\Teams\Application\Commands\CreateTeamHandler;
use App\Modules\Teams\Application\Commands\DeleteTeam;
use App\Modules\Teams\Application\Commands\DeleteTeamHandler;
use App\Modules\Teams\Application\Commands\LeaveTeam;
use App\Modules\Teams\Application\Commands\LeaveTeamHandler;
use App\Modules\Teams\Application\Commands\RemoveTeamMember;
use App\Modules\Teams\Application\Commands\RemoveTeamMemberHandler;
use App\Modules\Teams\Application\Commands\UpdateTeam;
use App\Modules\Teams\Application\Commands\UpdateTeamHandler;
use App\Modules\Teams\Application\Commands\UpdateTeamMemberRole;
use App\Modules\Teams\Application\Commands\UpdateTeamMemberRoleHandler;
use App\Modules\Teams\Application\Contracts\ManagesTeams;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Domain\Exceptions\TeamInvitationResponseRejected;
use App\Modules\Teams\Domain\Exceptions\TeamOperationRejected;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\Support\UserWithPersonalTeam;

test('creating a team selects it without changing URL defaults in the write layer', function () {
    $user = UserWithPersonalTeam::create();
    $previousTeamSlug = app(TeamContext::class)->currentForUser($user->id)->slug;
    URL::defaults(['current_team' => $previousTeamSlug]);

    $createdTeam = app(CreateTeamHandler::class)->handle(new CreateTeam($user->id, 'New Team'));

    expect($user->fresh()->current_team_id)->toBe($createdTeam->id)
        ->and(URL::getDefaultParameters()['current_team'])->toBe($previousTeamSlug);
});

test('leaving the only selected shared team clears the selection without a personal team', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = app(CreateTeamHandler::class)->handle(new CreateTeam($owner->id, 'Only Team'));
    Team::query()->findOrFail($team->id)->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
    DB::transaction(fn () => app(TeamSelectionAccounts::class)->select($member->id, $team->id));

    app(LeaveTeamHandler::class)->handle(new LeaveTeam($member->id, $team->id));

    expect($member->fresh()->current_team_id)->toBeNull()
        ->and(app(TeamContext::class)->belongsToTeam($member->id, $team->id))->toBeFalse();
});

test('deleting the only selected shared team clears the owner selection', function () {
    $owner = User::factory()->create();
    $team = app(CreateTeamHandler::class)->handle(new CreateTeam($owner->id, 'Only Team'));

    app(DeleteTeamHandler::class)->handle(new DeleteTeam($owner->id, $team->id));

    expect($owner->fresh()->current_team_id)->toBeNull();
    $this->assertSoftDeleted('teams', ['id' => $team->id]);
});

test('leaving another team preserves the selected team', function () {
    $firstOwner = User::factory()->create();
    $secondOwner = User::factory()->create();
    $member = User::factory()->create();
    $firstTeam = app(CreateTeamHandler::class)->handle(new CreateTeam($firstOwner->id, 'First Team'));
    $secondTeam = app(CreateTeamHandler::class)->handle(new CreateTeam($secondOwner->id, 'Second Team'));
    Team::query()->findOrFail($firstTeam->id)->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
    Team::query()->findOrFail($secondTeam->id)->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
    DB::transaction(fn () => app(TeamSelectionAccounts::class)->select($member->id, $secondTeam->id));

    app(LeaveTeamHandler::class)->handle(new LeaveTeam($member->id, $firstTeam->id));

    expect($member->fresh()->current_team_id)->toBe($secondTeam->id);
});

test('accept handler rejects invitations sent to another email address', function () {
    $owner = UserWithPersonalTeam::create();
    $recipient = UserWithPersonalTeam::create(['email' => 'recipient@example.com']);
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'someone-else@example.com',
        'invited_by' => $owner->id,
    ]);

    expect(fn () => app(AcceptTeamInvitationHandler::class)->handle(new AcceptTeamInvitation($recipient->id, $invitation->id)))
        ->toThrow(TeamInvitationResponseRejected::class);

    expect(app(TeamContext::class)->belongsToTeam($recipient->id, $team->id))->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

test('accept handler rejects accepted and expired invitations', function (string $state): void {
    $owner = UserWithPersonalTeam::create();
    $recipient = UserWithPersonalTeam::create(['email' => 'recipient@example.com']);
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $invitation = TeamInvitation::factory()->{$state}()->create([
        'team_id' => $team->id,
        'email' => $recipient->email,
        'invited_by' => $owner->id,
    ]);

    expect(fn () => app(AcceptTeamInvitationHandler::class)->handle(new AcceptTeamInvitation($recipient->id, $invitation->id)))
        ->toThrow(TeamInvitationResponseRejected::class);

    expect(app(TeamContext::class)->belongsToTeam($recipient->id, $team->id))->toBeFalse();
})->with(['accepted', 'expired']);

test('leave handler protects personal teams and team owners', function (bool $personal): void {
    $user = UserWithPersonalTeam::create();
    $team = $personal ? UserWithPersonalTeam::personalTeam($user) : Team::factory()->create();

    if (! $personal) {
        $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Owner]);
    }

    expect(fn () => app(LeaveTeamHandler::class)->handle(new LeaveTeam($user->id, $team->id)))
        ->toThrow(TeamOperationRejected::class);

    expect(app(TeamContext::class)->belongsToTeam($user->id, $team->id))->toBeTrue();
})->with([true, false]);

test('remove handler protects team owners', function () {
    $owner = UserWithPersonalTeam::create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    expect(fn () => app(RemoveTeamMemberHandler::class)->handle(new RemoveTeamMember($owner->id, $team->id, $owner->id)))
        ->toThrow(TeamOperationRejected::class);

    expect(app(TeamContext::class)->belongsToTeam($owner->id, $team->id))->toBeTrue();
});

test('delete handler protects personal teams', function () {
    $user = UserWithPersonalTeam::create();
    $team = UserWithPersonalTeam::personalTeam($user);

    expect(fn () => app(DeleteTeamHandler::class)->handle(new DeleteTeam($user->id, $team->id)))
        ->toThrow(TeamOperationRejected::class);

    $this->assertNotSoftDeleted('teams', ['id' => $team->id]);
});

test('team and user locks are acquired in canonical order', function () {
    $user = UserWithPersonalTeam::create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

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
    $member = UserWithPersonalTeam::create();
    $team = Team::factory()->create(['name' => 'Original Name']);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    expect(fn () => app(UpdateTeamHandler::class)->handle(new UpdateTeam($member->id, $team->id, 'Changed Name')))
        ->toThrow(TeamOperationRejected::class);

    expect($team->fresh()->name)->toBe('Original Name');
});

test('direct membership changes reject an executor without permission', function (string $action) {
    $actor = UserWithPersonalTeam::create();
    $member = UserWithPersonalTeam::create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $actor->id, 'role' => TeamRole::Admin]);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $operation = match ($action) {
        'update' => fn () => app(UpdateTeamMemberRoleHandler::class)->handle(new UpdateTeamMemberRole($actor->id, $team->id, $member->id, TeamRole::Admin)),
        'remove' => fn () => app(RemoveTeamMemberHandler::class)->handle(new RemoveTeamMember($actor->id, $team->id, $member->id)),
    };

    expect($operation)->toThrow(TeamOperationRejected::class);

    expect(app(TeamContext::class)->belongsToTeam($member->id, $team->id))->toBeTrue()
        ->and($team->memberships()->where('user_id', $member->id)->first()->role)->toBe(TeamRole::Member);
})->with(['update', 'remove']);

test('direct role changes cannot demote the current team owner', function () {
    $owner = UserWithPersonalTeam::create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    expect(fn () => app(UpdateTeamMemberRoleHandler::class)->handle(new UpdateTeamMemberRole($owner->id, $team->id, $owner->id, TeamRole::Member)))
        ->toThrow(TeamOperationRejected::class);

    expect($team->memberships()->where('user_id', $owner->id)->first()->role)->toBe(TeamRole::Owner);
});

test('team and several user locks follow ascending ids with an intermediate actor', function () {
    $lowerIdMember = UserWithPersonalTeam::create();
    $actor = UserWithPersonalTeam::create();
    $higherIdMember = UserWithPersonalTeam::create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $lowerIdMember->id, 'role' => TeamRole::Member]);
    $team->memberships()->create(['user_id' => $actor->id, 'role' => TeamRole::Owner]);
    $team->memberships()->create(['user_id' => $higherIdMember->id, 'role' => TeamRole::Member]);
    app(TeamSelectionAccounts::class)->select($lowerIdMember->id, $team->id);
    app(TeamSelectionAccounts::class)->select($higherIdMember->id, $team->id);

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
