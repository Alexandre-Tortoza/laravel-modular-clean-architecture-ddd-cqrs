<?php

use App\Modules\Identity\Application\Api\UserDirectory;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Teams\Application\Contracts\TeamQueries;
use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\CurrentTeamSelection;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\UserWithPersonalTeam;

test('returns a user team projection without exposing an Eloquent model', function () {
    $user = UserWithPersonalTeam::create();
    $team = Team::factory()->create(['name' => 'Product Team']);
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);

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
    $user = UserWithPersonalTeam::create();
    $team = Team::factory()->create(['name' => 'Product Team']);
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Admin]);
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

test('pending invitation queries exclude an invitation at its expiration time', function () {
    $this->travelTo('2026-01-01 12:00:00');

    $recipient = UserWithPersonalTeam::create(['email' => 'invited@example.com']);
    $invitation = TeamInvitation::factory()->create([
        'email' => $recipient->email,
        'expires_at' => '2026-01-01 12:01:00',
        'invited_by' => $recipient->id,
    ]);
    $queries = app(TeamQueries::class);

    expect($queries->pendingInvitationsForEmail($recipient->email))->toHaveCount(1)
        ->and($queries->pendingInvitationByCode($invitation->code))->not->toBeNull();

    $this->travelTo('2026-01-01 12:01:00');

    expect($queries->pendingInvitationsForEmail($recipient->email))->toBe([])
        ->and($queries->pendingInvitationByCode($invitation->code))->toBeNull();
});

test('returns no current team when a user has not selected one', function () {
    $user = UserWithPersonalTeam::create();
    app(CurrentTeamSelection::class)->select($user->id, null);

    expect(app(TeamQueries::class)->currentForUser($user->id))->toBeNull();
});

test('resolves the personal team when a user has not selected a current team', function () {
    $user = UserWithPersonalTeam::create();
    $personalTeam = UserWithPersonalTeam::personalTeam($user);
    app(CurrentTeamSelection::class)->select($user->id, null);

    $team = app(TeamQueries::class)->currentOrPersonalForUser($user->id);

    expect($team?->id)->toBe($personalTeam?->id)
        ->and($team?->isCurrent)->toBeFalse();
});

test('excludes the current team without changing its projection flag', function () {
    $user = UserWithPersonalTeam::create();
    $currentTeam = UserWithPersonalTeam::personalTeam($user);
    $otherTeam = Team::factory()->create(['name' => 'Product Team']);
    $otherTeam->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);

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

test('returns no fallback team for a user without memberships', function (): void {
    $user = User::factory()->create();

    expect(app(TeamQueries::class)->currentOrPersonalForUser($user->id))->toBeNull();
});

test('does not project a membership whose user is absent from the identity directory', function (): void {
    $user = UserWithPersonalTeam::create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);
    $directory = app(UserDirectory::class);
    $incompleteDirectory = Mockery::mock(UserDirectory::class);
    $incompleteDirectory->shouldReceive('find')->andReturnUsing(fn (int $id) => $directory->find($id));
    $incompleteDirectory->shouldReceive('findMany')->andReturn([]);
    app()->instance(UserDirectory::class, $incompleteDirectory);

    try {
        expect(app(TeamQueries::class)->editFor($user->id, $team->id)->members)->toBe([]);
    } finally {
        Mockery::close();
    }
});

test('reports an unknown user rather than returning another user team projection', function (): void {
    expect(fn () => app(TeamQueries::class)->forUser(999999))
        ->toThrow(ModelNotFoundException::class);
});
