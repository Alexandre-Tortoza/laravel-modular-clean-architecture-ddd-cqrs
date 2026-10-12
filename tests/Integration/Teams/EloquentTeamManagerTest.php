<?php

use App\Modules\Identity\Application\Api\TeamSelectionAccounts;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Teams\Application\Commands\CreateTeam;
use App\Modules\Teams\Application\Commands\CreateTeamHandler;
use App\Modules\Teams\Application\Commands\DeleteTeam;
use App\Modules\Teams\Application\Commands\DeleteTeamHandler;
use App\Modules\Teams\Application\Contracts\ManagesTeams;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Domain\Exceptions\TeamOperationRejected;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\UserWithPersonalTeam;

test('membership snapshot has no personal fallback for an existing user without a personal team', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

    $membership = app(ManagesTeams::class)->membershipForUpdate($team->id, $user->id);

    expect($membership->personalTeamId)->toBeNull()
        ->and($membership->role)->toBe(TeamRole::Member);
});

test('deleting a missing team preserves the account selection and existing memberships', function (): void {
    $user = UserWithPersonalTeam::create();
    $personalTeamId = $user->current_team_id;

    expect(fn () => app(DeleteTeamHandler::class)->handle(new DeleteTeam($user->id, PHP_INT_MAX)))
        ->toThrow(ModelNotFoundException::class);

    expect($user->fresh()->current_team_id)->toBe($personalTeamId);
    $this->assertDatabaseCount('teams', 1);
    $this->assertDatabaseHas('teams', ['id' => $personalTeamId, 'deleted_at' => null]);
    $this->assertDatabaseHas('team_members', ['team_id' => $personalTeamId, 'user_id' => $user->id, 'role' => TeamRole::Owner->value]);
});

test('team deletion rejects a fallback created outside its protected snapshot', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);
    app(TeamSelectionAccounts::class)->select($member->id, $team->id);
    $changed = false;
    DB::listen(function (QueryExecuted $query) use ($member, $team, &$changed): void {
        if ($changed || ! str_starts_with($query->sql, 'select * from "teams"') || ! str_contains($query->sql, '"team_members"')) {
            return;
        }

        $changed = true;
        app(CreateTeamHandler::class)->handle(new CreateTeam($member->id, 'New fallback', isPersonal: true));
        app(TeamSelectionAccounts::class)->select($member->id, $team->id);
    });

    expect(fn () => app(DeleteTeamHandler::class)->handle(new DeleteTeam($owner->id, $team->id)))
        ->toThrow(TeamOperationRejected::class, 'The available fallback teams changed. Please retry deleting the team.');

    expect($changed)->toBeTrue();
    expect($member->fresh()->current_team_id)->toBe($team->id);
    $this->assertDatabaseHas('teams', ['id' => $team->id, 'deleted_at' => null]);
    $this->assertDatabaseCount('teams', 1);
    $this->assertDatabaseHas('team_members', ['team_id' => $team->id, 'user_id' => $member->id]);
});
