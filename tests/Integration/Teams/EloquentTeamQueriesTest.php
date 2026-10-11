<?php

use App\Models\User;
use App\Modules\Teams\Application\Contracts\TeamQueries;
use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;

test('returns a user team projection without exposing an Eloquent model', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Product Team']);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $teams = app(TeamQueries::class)->forUser($user->id, includeCurrent: true);
    $productTeam = collect($teams)->first(
        fn (TeamData $team): bool => $team->name === 'Product Team',
    );

    expect($teams)->toHaveCount(2)
        ->and($productTeam)->not->toBeNull()
        ->and($productTeam->role)->toBe(TeamRole::Admin)
        ->and($productTeam)->not->toBeInstanceOf(Team::class);
});

test('returns current team permissions and pending invitation projections', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Product Team']);
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $user->email,
        'invited_by' => $user->id,
    ]);

    $queries = app(TeamQueries::class);
    $currentTeam = $queries->currentForUser($user->id);
    $permissions = $queries->permissionsFor($user->id, $team->id);
    $invitations = $queries->pendingInvitationsForEmail(strtoupper($user->email));
    $invitationByCode = $queries->pendingInvitationByCode($invitation->code);

    expect($currentTeam?->isCurrent)->toBeTrue()
        ->and($permissions->canUpdateTeam)->toBeTrue()
        ->and($permissions->canDeleteTeam)->toBeFalse()
        ->and($invitations)->toHaveCount(1)
        ->and($invitations[0]->inviterName)->toBe($user->name)
        ->and($invitationByCode?->teamName)->toBe('Product Team')
        ->and($queries->pendingInvitationByCode('missing'))->toBeNull();
});

test('returns no current team when a user has not selected one', function () {
    $user = User::factory()->create();
    $user->update(['current_team_id' => null]);

    expect(app(TeamQueries::class)->currentForUser($user->id))->toBeNull();
});

test('resolves the personal team when a user has not selected a current team', function () {
    $user = User::factory()->create();
    $personalTeam = $user->personalTeam();
    $user->update(['current_team_id' => null]);

    $team = app(TeamQueries::class)->currentOrPersonalForUser($user->id);

    expect($team?->id)->toBe($personalTeam?->id)
        ->and($team?->isCurrent)->toBeFalse();
});

test('excludes the current team without changing its projection flag', function () {
    $user = User::factory()->create();
    $currentTeam = $user->personalTeam();
    $otherTeam = Team::factory()->create(['name' => 'Product Team']);
    $otherTeam->members()->attach($user, ['role' => TeamRole::Member->value]);

    $queries = app(TeamQueries::class);
    $withoutCurrent = $queries->forUser($user->id);
    $withCurrent = $queries->forUser($user->id, includeCurrent: true);

    expect($withoutCurrent)->toHaveCount(1)
        ->and($withoutCurrent[0]->id)->toBe($otherTeam->id)
        ->and($withoutCurrent[0]->isCurrent)->toBeFalse()
        ->and($withCurrent)->toHaveCount(2)
        ->and(collect($withCurrent)->firstWhere('id', $currentTeam?->id)?->isCurrent)->toBeTrue()
        ->and(collect($withCurrent)->firstWhere('id', $otherTeam->id)?->isCurrent)->toBeFalse();
});
