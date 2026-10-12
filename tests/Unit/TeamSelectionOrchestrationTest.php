<?php

use App\Modules\Identity\Application\Api\TeamSelectionAccounts;
use App\Modules\Identity\Application\Api\UserRecord;
use App\Modules\Teams\Application\Commands\AcceptTeamInvitation;
use App\Modules\Teams\Application\Commands\AcceptTeamInvitationHandler;
use App\Modules\Teams\Application\Commands\CreateTeam;
use App\Modules\Teams\Application\Commands\CreateTeamHandler;
use App\Modules\Teams\Application\Contracts\CreatesTeams;
use App\Modules\Teams\Application\Contracts\ManagesTeamInvitations;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Application\Contracts\TeamClock;
use App\Modules\Teams\Application\Data\InvitationResponseData;
use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Domain\Policies\InvitationPolicy;
use App\Modules\Teams\Domain\Policies\TeamNamePolicy;

test('create handler selects the new team after persisting it inside the transaction', function (): void {
    $steps = [];
    $transactions = Mockery::mock(RunsTeamTransactions::class);
    $transactions->shouldReceive('run')->once()->andReturnUsing(function ($callback) use (&$steps) {
        $steps[] = 'begin';
        $result = $callback();
        $steps[] = 'end';

        return $result;
    });
    $selection = Mockery::mock(TeamSelectionAccounts::class);
    $selection->shouldReceive('lockMany')->once()->with([3])->andReturnUsing(function () use (&$steps): array {
        $steps[] = 'lock';

        return [3 => new UserRecord(3, 'Taylor', 'taylor@example.com', null)];
    });
    $teams = Mockery::mock(CreatesTeams::class);
    $teams->shouldReceive('create')->once()->with(3, 'New Team', false)
        ->andReturnUsing(function () use (&$steps): TeamData {
            $steps[] = 'create';

            return new TeamData(7, 'New Team', 'new-team', false, TeamRole::Owner);
        });
    $selection->shouldReceive('select')->once()->with(3, 7)->andReturnUsing(function () use (&$steps): void {
        $steps[] = 'select';
    });

    $created = (new CreateTeamHandler($teams, $transactions, $selection, new TeamNamePolicy))->handle(new CreateTeam(3, 'New Team'));

    expect($steps)->toBe(['begin', 'lock', 'create', 'select', 'end'])
        ->and($created->isCurrent)->toBeTrue();
});

test('accept handler selects the invitation team after persisting acceptance', function (): void {
    $steps = [];
    $invitation = new InvitationResponseData(9, 7, 'new-team', 3, 'taylor@example.com', 'taylor@example.com', TeamRole::Member, null, null);
    $transactions = Mockery::mock(RunsTeamTransactions::class);
    $transactions->shouldReceive('run')->once()->andReturnUsing(function ($callback) use (&$steps) {
        $steps[] = 'begin';
        $result = $callback();
        $steps[] = 'end';

        return $result;
    });
    $invitations = Mockery::mock(ManagesTeamInvitations::class);
    $invitations->shouldReceive('responseForUpdate')->once()->with(3, 9)->andReturn($invitation);
    $invitations->shouldReceive('accept')->once()->with($invitation, Mockery::on(
        fn (DateTimeImmutable $acceptedAt): bool => $acceptedAt == new DateTimeImmutable('2026-01-04 12:00:00'),
    ))
        ->andReturnUsing(function () use (&$steps): void {
            $steps[] = 'accept';
        });
    $selection = Mockery::mock(TeamSelectionAccounts::class);
    $selection->shouldReceive('select')->once()->with(3, 7)->andReturnUsing(function () use (&$steps): void {
        $steps[] = 'select';
    });
    $clock = Mockery::mock(TeamClock::class);
    $clock->shouldReceive('now')->once()->andReturn(new DateTimeImmutable('2026-01-04 12:00:00'));

    $slug = (new AcceptTeamInvitationHandler($invitations, $transactions, $clock, new InvitationPolicy, $selection))
        ->handle(new AcceptTeamInvitation(3, 9));

    expect($steps)->toBe(['begin', 'accept', 'select', 'end'])
        ->and($slug)->toBe('new-team');
});
