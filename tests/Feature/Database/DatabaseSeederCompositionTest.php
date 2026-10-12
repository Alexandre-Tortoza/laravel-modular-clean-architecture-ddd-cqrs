<?php

use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Identity\Infrastructure\Persistence\Seeders\DemoUserSeeder;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Membership;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Seeders\DemoPersonalTeamSeeder;
use Database\Seeders\DemoDatabaseSeeder;

test('standard seeding creates no demo credentials or teams', function (string $environment): void {
    app()->instance('env', $environment);

    $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('teams', 0);
    $this->assertDatabaseCount('team_members', 0);
})->with(['local', 'testing', 'production']);

test('demo seeders cannot be forced outside local and testing', function (string $seeder, string $environment): void {
    User::factory()->create(['email' => 'test@example.com']);
    app()->instance('env', $environment);

    expect(fn () => $this->artisan('db:seed', ['--class' => $seeder, '--force' => true])->run())
        ->toThrow(LogicException::class, 'Demo data may only be seeded in local or testing environments.');

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('teams', 0);
    $this->assertDatabaseCount('team_members', 0);
})->with([DemoDatabaseSeeder::class, DemoUserSeeder::class, DemoPersonalTeamSeeder::class])->with(['production', 'staging']);

test('demo seed composes the existing user with a personal team', function (string $environment) {
    app()->instance('env', $environment);
    $this->artisan('db:seed', ['--class' => DemoDatabaseSeeder::class])->assertExitCode(0);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $team = Team::query()->findOrFail($user->current_team_id);
    $membership = Membership::query()
        ->where('user_id', $user->id)
        ->where('team_id', $team->id)
        ->firstOrFail();

    expect($user->name)->toBe('Test User')
        ->and($team->name)->toBe("Test User's Team")
        ->and($team->is_personal)->toBeTrue()
        ->and($membership->role)->toBe(TeamRole::Owner);

    $this->artisan('db:seed', ['--class' => DemoDatabaseSeeder::class])->assertExitCode(0);

    expect(User::query()->where('email', 'test@example.com')->count())->toBe(1)
        ->and(Team::query()->where('is_personal', true)->count())->toBe(1);
})->with(['local', 'testing']);
