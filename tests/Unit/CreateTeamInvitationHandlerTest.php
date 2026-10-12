<?php

use App\Modules\Teams\Application\Commands\CreateTeamInvitation;
use App\Modules\Teams\Application\Commands\CreateTeamInvitationHandler;
use App\Modules\Teams\Application\Contracts\ManagesTeamInvitations;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Application\Contracts\SendsTeamInvitations;
use App\Modules\Teams\Application\Contracts\TeamClock;
use App\Modules\Teams\Application\Data\InvitationCreationData;
use App\Modules\Teams\Application\Data\TeamInvitationData;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Domain\Policies\InvitationPolicy;

it('does not notify when the invitation transaction rolls back', function (): void {
    $transactions = new class implements RunsTeamTransactions
    {
        public function run(Closure $callback): mixed
        {
            $callback();

            throw new RuntimeException('Transaction rolled back.');
        }
    };
    $invitations = Mockery::mock(ManagesTeamInvitations::class);
    $invitations->shouldReceive('creationForUpdate')->once()->with(7, 3, 'recipient@example.com')
        ->andReturn(new InvitationCreationData(TeamRole::Owner, 'Taylor', false, []));
    $invitations->shouldReceive('create')->once()->with(7, 3, 'recipient@example.com', TeamRole::Member, 'Taylor', Mockery::on(
        fn (DateTimeImmutable $expiresAt): bool => $expiresAt == new DateTimeImmutable('2026-01-07 12:00:00'),
    ))
        ->andReturn(new TeamInvitationData(9, 'code', 'recipient@example.com', 7, 'Acme', 'Taylor', TeamRole::Member));
    $sender = Mockery::mock(SendsTeamInvitations::class);
    $sender->shouldNotReceive('send');
    $clock = Mockery::mock(TeamClock::class);
    $clock->shouldReceive('now')->once()->andReturn(new DateTimeImmutable('2026-01-04 12:00:00'));

    try {
        expect(fn () => (new CreateTeamInvitationHandler($invitations, $transactions, $sender, new InvitationPolicy, $clock))
            ->handle(new CreateTeamInvitation(7, 3, 'recipient@example.com', TeamRole::Member)))
            ->toThrow(RuntimeException::class, 'Transaction rolled back.');
    } finally {
        Mockery::close();
    }
});
