<?php

use App\Modules\Identity\Application\Api\TeamSelectionAccounts;
use App\Modules\Teams\Application\Api\TeamContext;
use App\Modules\Teams\Application\Commands\SwitchCurrentTeam;
use App\Modules\Teams\Application\Commands\SwitchCurrentTeamHandler;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Domain\Exceptions\TeamOperationRejected;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Presentation\Rules\TeamName;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\UserWithPersonalTeam;

test('the teams index page can be rendered', function () {
    $user = UserWithPersonalTeam::create();

    $response = $this
        ->actingAs($user)
        ->get(route('teams.index'));

    $response->assertOk();
});

test('teams can be created', function () {
    $user = UserWithPersonalTeam::create();

    $response = $this
        ->actingAs($user)
        ->post(route('teams.store'), [
            'name' => 'Test Team',
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('teams', [
        'name' => 'Test Team',
        'is_personal' => false,
    ]);
});

test('team slug uses next available suffix', function () {
    $user = UserWithPersonalTeam::create();

    Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    Team::factory()->create(['name' => 'Acme One', 'slug' => 'acme-1']);
    Team::factory()->create(['name' => 'Acme Ten', 'slug' => 'acme-10']);

    $this
        ->actingAs($user)
        ->post(route('teams.store'), [
            'name' => 'Acme',
        ]);

    $this->assertDatabaseHas('teams', [
        'name' => 'Acme',
        'slug' => 'acme-11',
    ]);
});

test('team slug ignores non-numeric matching suffixes', function () {
    $user = UserWithPersonalTeam::create();

    Team::factory()->create(['name' => 'Acme Release', 'slug' => 'acme-release']);

    $this
        ->actingAs($user)
        ->post(route('teams.store'), ['name' => 'Acme'])
        ->assertRedirect();

    $this->assertDatabaseHas('teams', ['name' => 'Acme', 'slug' => 'acme-1']);
});

test('users cannot switch to teams they do not belong to through the handler', function () {
    $user = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    expect(fn () => app(SwitchCurrentTeamHandler::class)->handle(new SwitchCurrentTeam($user->id, $team->id)))->toThrow(TeamOperationRejected::class)
        ->and($user->fresh()->current_team_id)->not->toBe($team->id);
});

test('reserved team names are rejected', function () {
    $user = UserWithPersonalTeam::create();

    $this
        ->actingAs($user)
        ->post(route('teams.store'), ['name' => 'dashboard'])
        ->assertSessionHasErrors('name');
});

test('team name rule accepts names outside the reserved list', function () {
    $validation = Validator::make(['name' => 'Product Team'], ['name' => [new TeamName]]);

    expect($validation->passes())->toBeTrue();
});

test('team name rule rejects normalized reserved names', function () {
    $validation = Validator::make(['name' => ' Dashboard '], ['name' => [new TeamName]]);

    expect($validation->fails())->toBeTrue()
        ->and($validation->errors()->first('name'))->toBe('This team name is reserved and cannot be used.');
});

test('the team edit page can be rendered', function () {
    $user = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Owner]);

    $response = $this
        ->actingAs($user)
        ->get(route('teams.edit', $team));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/Edit')
            ->where('members.0.role', TeamRole::Owner->value)
            ->where('members.0.role_label', TeamRole::Owner->label()),
        );
});

test('teams can be updated by owners', function () {
    $user = UserWithPersonalTeam::create();
    $team = Team::factory()->create(['name' => 'Original Name']);

    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Owner]);

    $response = $this
        ->actingAs($user)
        ->patch(route('teams.update', $team), [
            'name' => 'Updated Name',
        ]);

    $response->assertRedirect(route('teams.edit', $team->fresh()));

    $this->assertDatabaseHas('teams', [
        'id' => $team->id,
        'name' => 'Updated Name',
    ]);
});

test('teams cannot be updated by members', function () {
    $owner = UserWithPersonalTeam::create();
    $member = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $response = $this
        ->actingAs($member)
        ->patch(route('teams.update', $team), [
            'name' => 'Updated Name',
        ]);

    $response->assertForbidden();
});

test('teams can be deleted by owners', function () {
    $user = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Owner]);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('teams', [
        'id' => $team->id,
    ]);
});

test('team deletion requires name confirmation', function () {
    $user = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Owner]);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $team), [
            'name' => 'Wrong Name',
        ]);

    $response->assertSessionHasErrors('name');

    $this->assertDatabaseHas('teams', [
        'id' => $team->id,
        'deleted_at' => null,
    ]);
});

test('deleting current team switches to alphabetically first remaining team', function () {
    $user = UserWithPersonalTeam::create(['name' => 'Mike']);

    $zuluTeam = Team::factory()->create(['name' => 'Zulu Team']);
    $zuluTeam->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Owner]);

    $alphaTeam = Team::factory()->create(['name' => 'Alpha Team']);
    $alphaTeam->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Owner]);

    $betaTeam = Team::factory()->create(['name' => 'Beta Team']);
    $betaTeam->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Owner]);

    app(TeamSelectionAccounts::class)->select($user->id, $zuluTeam->id);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $zuluTeam), [
            'name' => $zuluTeam->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('teams', [
        'id' => $zuluTeam->id,
    ]);

    expect($user->fresh()->current_team_id)->toEqual($alphaTeam->id);
});

test('deleting current team falls back to personal team when alphabetically first', function () {
    $user = UserWithPersonalTeam::create();
    $personalTeam = UserWithPersonalTeam::personalTeam($user);
    $team = Team::factory()->create(['name' => 'Zulu Team']);
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Owner]);

    app(TeamSelectionAccounts::class)->select($user->id, $team->id);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('teams', [
        'id' => $team->id,
    ]);

    expect($user->fresh()->current_team_id)->toEqual($personalTeam->id);
});

test('deleting non current team leaves current team unchanged', function () {
    $user = UserWithPersonalTeam::create();
    $personalTeam = UserWithPersonalTeam::personalTeam($user);
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Owner]);

    app(TeamSelectionAccounts::class)->select($user->id, $personalTeam->id);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('teams', [
        'id' => $team->id,
    ]);

    expect($user->fresh()->current_team_id)->toEqual($personalTeam->id);
});

test('members can leave non personal teams', function () {
    $owner = UserWithPersonalTeam::create();
    $member = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $response = $this
        ->actingAs($member)
        ->delete(route('teams.leave', $team));

    $response->assertRedirect(route('teams.index'));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => "You left the team \"{$team->name}\""]);

    expect(app(TeamContext::class)->belongsToTeam($member->id, $team->id))->toBeFalse();
});

test('leaving current team switches to alphabetically first remaining team', function () {
    $owner = UserWithPersonalTeam::create();
    $member = UserWithPersonalTeam::create(['name' => 'Mike']);

    $zuluTeam = Team::factory()->create(['name' => 'Zulu Team']);
    $zuluTeam->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $zuluTeam->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $alphaTeam = Team::factory()->create(['name' => 'Alpha Team']);
    $alphaTeam->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $betaTeam = Team::factory()->create(['name' => 'Beta Team']);
    $betaTeam->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    app(TeamSelectionAccounts::class)->select($member->id, $zuluTeam->id);

    $response = $this
        ->actingAs($member)
        ->delete(route('teams.leave', $zuluTeam));

    $response->assertRedirect(route('teams.index'));

    expect(app(TeamContext::class)->belongsToTeam($member->id, $zuluTeam->id))->toBeFalse();
    expect($member->fresh()->current_team_id)->toEqual($alphaTeam->id);
});

test('personal teams cannot be left', function () {
    $user = UserWithPersonalTeam::create();
    $personalTeam = UserWithPersonalTeam::personalTeam($user);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.leave', $personalTeam));

    $response->assertForbidden();

    expect(app(TeamContext::class)->belongsToTeam($user->id, $personalTeam->id))->toBeTrue();
});

test('team owners cannot leave their team', function () {
    $owner = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.leave', $team));

    $response->assertForbidden();

    expect(app(TeamContext::class)->belongsToTeam($owner->id, $team->id))->toBeTrue();
});

test('users cannot leave teams they dont belong to', function () {
    $user = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.leave', $team));

    $response->assertForbidden();
});

test('deleting team switches other affected users to their personal team', function () {
    $owner = UserWithPersonalTeam::create();
    $member = UserWithPersonalTeam::create();

    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    app(TeamSelectionAccounts::class)->select($owner->id, $team->id);
    app(TeamSelectionAccounts::class)->select($member->id, $team->id);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertRedirect();

    expect($member->fresh()->current_team_id)->toEqual(UserWithPersonalTeam::personalTeam($member)->id);
});

test('personal teams cannot be deleted', function () {
    $user = UserWithPersonalTeam::create();

    $personalTeam = UserWithPersonalTeam::personalTeam($user);

    $response = $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $personalTeam), [
            'name' => $personalTeam->name,
        ]);

    $response->assertForbidden();

    $this->assertDatabaseHas('teams', [
        'id' => $personalTeam->id,
        'deleted_at' => null,
    ]);
});

test('teams cannot be deleted by non owners', function () {
    $owner = UserWithPersonalTeam::create();
    $member = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $response = $this
        ->actingAs($member)
        ->delete(route('teams.destroy', $team), [
            'name' => $team->name,
        ]);

    $response->assertForbidden();
});

test('users can switch teams', function () {
    $user = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    $response = $this
        ->actingAs($user)
        ->post(route('teams.switch', $team));

    $response->assertRedirect();

    expect($user->fresh()->current_team_id)->toEqual($team->id);
});

test('users cannot switch to team they dont belong to', function () {
    $user = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('teams.switch', $team));

    $response->assertForbidden();
});

test('guests cannot access teams', function () {
    $response = $this->get(route('teams.index'));

    $response->assertRedirect(route('login'));
});
