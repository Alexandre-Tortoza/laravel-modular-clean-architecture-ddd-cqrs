<?php

use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\UserWithPersonalTeam;

test('the health endpoint is available without authentication', function () {
    $this->get('/up')->assertOk();

    $this->assertGuest();
});

test('a login session supports team creation and Inertia navigation until logout', function () {
    $user = UserWithPersonalTeam::create();
    $personalTeam = UserWithPersonalTeam::personalTeam($user);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', ['current_team' => $personalTeam->slug]));

    $this->assertAuthenticatedAs($user);

    $this->post(route('teams.store'), ['name' => 'Infrastructure Team'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('teams.edit', ['team' => 'infrastructure-team']));

    $team = Team::query()->where('slug', 'infrastructure-team')->firstOrFail();

    $this->get(route('teams.edit', $team))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/Edit')
            ->where('team.id', $team->id)
            ->where('team.name', 'Infrastructure Team')
            ->where('team.isPersonal', false)
            ->where('members.0.id', $user->id)
            ->where('members.0.role', 'owner'),
        );

    $this->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('currentTeam.id', $team->id)
            ->where('currentTeam.slug', $team->slug),
        );

    $this->post(route('logout'))->assertRedirect(route('home'));

    $this->assertGuest();

    $this->get(route('teams.edit', $team))->assertRedirect(route('login'));
});
