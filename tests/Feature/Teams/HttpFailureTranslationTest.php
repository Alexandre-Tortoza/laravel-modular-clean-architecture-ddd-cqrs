<?php

use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Teams\Application\Commands\AcceptTeamInvitationHandler;
use App\Modules\Teams\Application\Commands\CancelTeamInvitationHandler;
use App\Modules\Teams\Application\Commands\CreateTeamHandler;
use App\Modules\Teams\Application\Commands\CreateTeamInvitationHandler;
use App\Modules\Teams\Application\Commands\DeclineTeamInvitationHandler;
use App\Modules\Teams\Application\Commands\DeleteTeamHandler;
use App\Modules\Teams\Application\Commands\LeaveTeamHandler;
use App\Modules\Teams\Application\Commands\SwitchCurrentTeamHandler;
use App\Modules\Teams\Application\Commands\UpdateTeamHandler;
use App\Modules\Teams\Application\Contracts\TeamQueries;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Domain\Exceptions\InvalidTeamName;
use App\Modules\Teams\Domain\Exceptions\TeamInvitationCreationRejected;
use App\Modules\Teams\Domain\Exceptions\TeamInvitationResponseRejected;
use App\Modules\Teams\Domain\Exceptions\TeamOperationRejected;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;
use Tests\Support\UserWithPersonalTeam;

function failureTranslationTeam(User $user, TeamRole $role = TeamRole::Owner): Team
{
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => $role]);

    return $team;
}

function rejectTeamAction(string $handlerClass): void
{
    $handler = Mockery::mock($handlerClass);
    $handler->shouldReceive('handle')->once()->andThrow(new TeamOperationRejected('Team state changed.'));
    app()->instance($handlerClass, $handler);
}

test('team update returns forbidden when application rejects a stale operation', function () {
    $user = UserWithPersonalTeam::create();
    $team = failureTranslationTeam($user);
    rejectTeamAction(UpdateTeamHandler::class);

    $this->actingAs($user)->patchJson(route('teams.update', $team), ['name' => 'New Name'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Team state changed.');

    expect($team->fresh()->name)->toBe($team->name);
});

test('team update returns name validation when the application rejects a direct invariant violation', function () {
    $user = UserWithPersonalTeam::create();
    $team = failureTranslationTeam($user);
    $handler = Mockery::mock(UpdateTeamHandler::class);
    $handler->shouldReceive('handle')->once()->andThrow(new InvalidTeamName('The team name is required.'));
    app()->instance(UpdateTeamHandler::class, $handler);

    $this->actingAs($user)->patchJson(route('teams.update', $team), ['name' => 'Valid request name'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => 'The team name is required.']);
});

test('team creation returns name validation when the application rejects a direct invariant violation', function () {
    $user = UserWithPersonalTeam::create();
    $handler = Mockery::mock(CreateTeamHandler::class);
    $handler->shouldReceive('handle')->once()->andThrow(new InvalidTeamName('The team name is required.'));
    app()->instance(CreateTeamHandler::class, $handler);

    $this->actingAs($user)->postJson(route('teams.store'), ['name' => 'Valid request name'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => 'The team name is required.']);
});

test('team edit returns forbidden when the query rejects a membership changed after middleware', function () {
    $user = UserWithPersonalTeam::create();
    $team = failureTranslationTeam($user, TeamRole::Member);
    $queries = Mockery::mock(TeamQueries::class);
    $queries->shouldReceive('editFor')->once()->with($user->id, $team->id)
        ->andThrow(new TeamOperationRejected('Team membership changed.'));
    app()->instance(TeamQueries::class, $queries);

    $this->actingAs($user)->getJson(route('teams.edit', $team))
        ->assertForbidden()
        ->assertJsonPath('message', 'Team membership changed.');
});

test('team switch returns forbidden when application rejects a stale membership', function () {
    $user = UserWithPersonalTeam::create();
    $team = failureTranslationTeam($user, TeamRole::Member);
    rejectTeamAction(SwitchCurrentTeamHandler::class);

    $this->actingAs($user)->postJson(route('teams.switch', $team))
        ->assertForbidden()
        ->assertJsonPath('message', 'Team state changed.');

    expect($user->fresh()->current_team_id)->not->toBe($team->id);
});

test('team leave returns forbidden when application rejects a stale membership', function () {
    $user = UserWithPersonalTeam::create();
    $team = failureTranslationTeam($user, TeamRole::Member);
    rejectTeamAction(LeaveTeamHandler::class);

    $this->actingAs($user)->deleteJson(route('teams.leave', $team))
        ->assertForbidden()
        ->assertJsonPath('message', 'Team state changed.');

    $this->assertDatabaseHas('team_members', ['team_id' => $team->id, 'user_id' => $user->id]);
});

test('team delete returns forbidden when application rejects a stale owner', function () {
    $user = UserWithPersonalTeam::create();
    $team = failureTranslationTeam($user);
    rejectTeamAction(DeleteTeamHandler::class);

    $this->actingAs($user)->deleteJson(route('teams.destroy', $team), ['name' => $team->name])
        ->assertForbidden()
        ->assertJsonPath('message', 'Team state changed.');

    $this->assertDatabaseHas('teams', ['id' => $team->id, 'deleted_at' => null]);
});

test('invitation creation returns email validation error when application rejects a duplicate', function () {
    $user = UserWithPersonalTeam::create();
    $team = failureTranslationTeam($user);
    $handler = Mockery::mock(CreateTeamInvitationHandler::class);
    $handler->shouldReceive('handle')->once()->andThrow(new TeamInvitationCreationRejected('Invitation already exists.'));
    app()->instance(CreateTeamInvitationHandler::class, $handler);

    $this->actingAs($user)->postJson(route('teams.invitations.store', $team), [
        'email' => 'new@example.com',
        'role' => TeamRole::Member->value,
    ])->assertUnprocessable()->assertJsonValidationErrors(['email' => 'Invitation already exists.']);

    $this->assertDatabaseMissing('team_invitations', ['team_id' => $team->id, 'email' => 'new@example.com']);
});

test('invitation creation returns forbidden when application rejects a stale permission', function () {
    $user = UserWithPersonalTeam::create();
    $team = failureTranslationTeam($user);
    $handler = Mockery::mock(CreateTeamInvitationHandler::class);
    $handler->shouldReceive('handle')->once()->andThrow(new TeamOperationRejected('Team state changed.'));
    app()->instance(CreateTeamInvitationHandler::class, $handler);

    $this->actingAs($user)->postJson(route('teams.invitations.store', $team), [
        'email' => 'new@example.com',
        'role' => TeamRole::Member->value,
    ])->assertForbidden()->assertJsonPath('message', 'Team state changed.');

    $this->assertDatabaseMissing('team_invitations', ['team_id' => $team->id, 'email' => 'new@example.com']);
});

test('invitation cancellation returns forbidden when application rejects a stale permission', function () {
    $user = UserWithPersonalTeam::create();
    $team = failureTranslationTeam($user);
    $invitation = TeamInvitation::factory()->create(['team_id' => $team->id, 'invited_by' => $user->id]);
    rejectTeamAction(CancelTeamInvitationHandler::class);

    $this->actingAs($user)->deleteJson(route('teams.invitations.destroy', [$team, $invitation]))
        ->assertForbidden()
        ->assertJsonPath('message', 'Team state changed.');

    $this->assertModelExists($invitation);
});

test('invitation acceptance returns validation error when application rejects a stale invitation', function () {
    $owner = UserWithPersonalTeam::create();
    $recipient = UserWithPersonalTeam::create(['email' => 'recipient@example.com']);
    $team = failureTranslationTeam($owner);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $recipient->email,
        'invited_by' => $owner->id,
    ]);
    $handler = Mockery::mock(AcceptTeamInvitationHandler::class);
    $handler->shouldReceive('handle')->once()->andThrow(new TeamInvitationResponseRejected('Invitation expired.'));
    app()->instance(AcceptTeamInvitationHandler::class, $handler);

    $this->actingAs($recipient)->postJson(route('invitations.accept', $invitation))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['invitation' => 'Invitation expired.']);

    expect($invitation->fresh()->accepted_at)->toBeNull();
});

test('invitation decline returns validation error when application rejects a stale invitation', function () {
    $owner = UserWithPersonalTeam::create();
    $recipient = UserWithPersonalTeam::create(['email' => 'recipient@example.com']);
    $team = failureTranslationTeam($owner);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $recipient->email,
        'invited_by' => $owner->id,
    ]);
    $handler = Mockery::mock(DeclineTeamInvitationHandler::class);
    $handler->shouldReceive('handle')->once()->andThrow(new TeamInvitationResponseRejected('Invitation expired.'));
    app()->instance(DeclineTeamInvitationHandler::class, $handler);

    $this->actingAs($recipient)->deleteJson(route('invitations.decline', $invitation))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['invitation' => 'Invitation expired.']);

    $this->assertModelExists($invitation);
});
