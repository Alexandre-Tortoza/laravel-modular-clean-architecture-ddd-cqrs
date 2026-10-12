<?php

use App\Modules\Teams\Application\Api\TeamContext;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\UserWithPersonalTeam;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard', ['current_team' => 'missing-team']));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = UserWithPersonalTeam::create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard', ['current_team' => Team::query()->findOrFail($user->current_team_id)->slug]));

    $response->assertOk();
});

test('a dashboard route uses its team as request context without changing the saved selection', function () {
    $user = UserWithPersonalTeam::create();
    $savedTeam = Team::query()->findOrFail($user->current_team_id);
    $routeTeam = Team::factory()->create(['name' => 'Route Team']);
    $routeTeam->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => $routeTeam->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentTeam.id', $routeTeam->id)
            ->where('currentTeam.slug', $routeTeam->slug),
        );

    expect($user->fresh()->current_team_id)->toBe($savedTeam->id);
});

test('a dashboard HEAD request does not change the saved team selection', function () {
    $user = UserWithPersonalTeam::create();
    $savedTeamId = $user->current_team_id;
    $routeTeam = Team::factory()->create(['name' => 'Head Route Team']);
    $routeTeam->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    $this->actingAs($user)
        ->head(route('dashboard', ['current_team' => $routeTeam->slug]))
        ->assertOk();

    expect($user->fresh()->current_team_id)->toBe($savedTeamId);
});

test('authenticated users without a current team receive no current team prop', function () {
    $user = UserWithPersonalTeam::create();
    $queries = Mockery::mock(TeamContext::class);
    $queries->shouldReceive('belongsToTeam')->with($user->id, $user->current_team_id)->andReturnTrue();
    $queries->shouldReceive('currentForUser')->with($user->id)->andReturnNull();
    $queries->shouldReceive('forUser')->with($user->id, true)->andReturn([]);
    $queries->shouldReceive('pendingInvitationsForEmail')->with($user->email)->andReturn([]);
    $this->app->instance(TeamContext::class, $queries);

    $this->actingAs($user)
        ->get(route('dashboard', ['current_team' => Team::query()->findOrFail($user->current_team_id)->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('currentTeam', null));
});

test('dashboard includes pending invitations for the authenticated user', function () {
    $owner = UserWithPersonalTeam::create(['name' => 'Taylor Otwell']);
    $invitedUser = UserWithPersonalTeam::create(['email' => 'invited@example.com']);
    $team = Team::factory()->create(['name' => 'Laravel Team']);

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard', ['current_team' => Team::query()->findOrFail($invitedUser->current_team_id)->slug]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 1)
        ->where('pendingInvitations.0.code', $invitation->code)
        ->where('pendingInvitations.0.inviterName', 'Taylor Otwell')
        ->where('pendingInvitations.0.team.name', 'Laravel Team')
        ->where('pendingInvitations.0.team.slug', $team->slug)
        ->missing('pendingInvitations.0.teamName'),
    );
});

test('dashboard does not include accepted invitations', function () {
    $owner = UserWithPersonalTeam::create();
    $invitedUser = UserWithPersonalTeam::create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    TeamInvitation::factory()->accepted()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard', ['current_team' => Team::query()->findOrFail($invitedUser->current_team_id)->slug]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );
});

test('dashboard excludes expired invitations without deleting them', function () {
    $owner = UserWithPersonalTeam::create();
    $invitedUser = UserWithPersonalTeam::create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard', ['current_team' => Team::query()->findOrFail($invitedUser->current_team_id)->slug]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('dashboard does not include or delete other users invitations', function () {
    $owner = UserWithPersonalTeam::create();
    $invitedUser = UserWithPersonalTeam::create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'someone@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard', ['current_team' => Team::query()->findOrFail($invitedUser->current_team_id)->slug]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});
