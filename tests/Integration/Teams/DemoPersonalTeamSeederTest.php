<?php

use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Seeders\DemoPersonalTeamSeeder;

test('does not create a team when the demo identity has not been seeded', function (): void {
    app(DemoPersonalTeamSeeder::class)->run();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('teams', 0);
});

test('creates a personal team when the demo user already belongs to a shared team', function (): void {
    $user = User::factory()->create(['email' => 'test@example.com']);
    $sharedTeam = Team::factory()->create(['is_personal' => false]);
    $sharedTeam->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    app(DemoPersonalTeamSeeder::class)->run();

    $personalTeam = Team::query()->where('is_personal', true)->sole();

    expect($user->fresh()->current_team_id)->toBe($personalTeam->id)
        ->and($personalTeam->memberships()->where('user_id', $user->id)->exists())->toBeTrue()
        ->and($sharedTeam->fresh())->not->toBeNull();
});
