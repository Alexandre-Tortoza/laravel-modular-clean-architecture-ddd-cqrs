<?php

use App\Modules\Identity\Application\Api\TeamSelectionAccounts;
use App\Modules\Teams\Application\Api\TeamContext;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use Tests\Support\UserWithPersonalTeam;

test('team member roles can be updated by owners', function () {
    $owner = UserWithPersonalTeam::create();
    $member = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $response = $this
        ->actingAs($owner)
        ->patch(route('teams.members.update', [$team, $member]), [
            'role' => TeamRole::Admin->value,
        ]);

    $response->assertRedirect(route('teams.edit', $team));

    expect($team->memberships()->where('user_id', $member->id)->first()->role->value)->toEqual(TeamRole::Admin->value);
});

test('team member roles cannot be updated by non owners', function () {
    $owner = UserWithPersonalTeam::create();
    $admin = UserWithPersonalTeam::create();
    $member = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $response = $this
        ->actingAs($admin)
        ->patch(route('teams.members.update', [$team, $member]), [
            'role' => TeamRole::Admin->value,
        ]);

    $response->assertForbidden();
});

test('team members can be removed by owners', function () {
    $owner = UserWithPersonalTeam::create();
    $member = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $member]));

    $response->assertRedirect(route('teams.edit', $team));

    expect(app(TeamContext::class)->belongsToTeam($member->id, $team->id))->toBeFalse();
});

test('team members cannot be removed by non owners', function () {
    $owner = UserWithPersonalTeam::create();
    $admin = UserWithPersonalTeam::create();
    $member = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $response = $this
        ->actingAs($admin)
        ->delete(route('teams.members.destroy', [$team, $member]));

    $response->assertForbidden();
});

test('team owner cannot be removed', function () {
    $owner = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $owner]));

    $response->assertForbidden();

    expect(app(TeamContext::class)->belongsToTeam($owner->id, $team->id))->toBeTrue();
});

test('team member role cannot be set to owner', function () {
    $owner = UserWithPersonalTeam::create();
    $member = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $response = $this
        ->actingAs($owner)
        ->patch(route('teams.members.update', [$team, $member]), [
            'role' => TeamRole::Owner->value,
        ]);

    $response->assertSessionHasErrors('role');

    expect($team->memberships()->where('user_id', $member->id)->first()->role->value)->toEqual(TeamRole::Member->value);
});

test('team owner cannot be demoted through the member endpoint', function () {
    $owner = UserWithPersonalTeam::create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    $response = $this
        ->actingAs($owner)
        ->patch(route('teams.members.update', [$team, $owner]), [
            'role' => TeamRole::Member->value,
        ]);

    $response->assertForbidden();

    expect($team->memberships()->where('user_id', $owner->id)->first()->role)->toBe(TeamRole::Owner);
});

test('removed member current team is set to personal team', function () {
    $owner = UserWithPersonalTeam::create();
    $member = UserWithPersonalTeam::create();
    $personalTeam = UserWithPersonalTeam::personalTeam($member);
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    app(TeamSelectionAccounts::class)->select($member->id, $team->id);

    $this
        ->actingAs($owner)
        ->delete(route('teams.members.destroy', [$team, $member]));

    expect($member->fresh()->current_team_id)->toEqual($personalTeam->id);
});

test('a malformed member route key returns not found', function (): void {
    $owner = UserWithPersonalTeam::create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    $this->actingAs($owner)
        ->patch(route('teams.members.update', ['team' => $team, 'user' => 'invalid']), ['role' => TeamRole::Admin->value])
        ->assertNotFound();
});
