<?php

use App\Modules\Teams\Application\Commands\UpdateTeam;
use App\Modules\Teams\Application\Commands\UpdateTeamHandler;
use App\Modules\Teams\Application\Contracts\ManagesTeams;
use App\Modules\Teams\Application\Contracts\RunsTeamTransactions;
use App\Modules\Teams\Application\Data\TeamData;
use App\Modules\Teams\Application\Data\TeamMembershipData;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Domain\Exceptions\TeamOperationRejected;
use App\Modules\Teams\Domain\Policies\TeamLifecyclePolicy;

it('rejects a role read inside the transaction without writing through the port', function (): void {
    $transactions = new class implements RunsTeamTransactions
    {
        public bool $inside = false;

        public function run(Closure $callback): mixed
        {
            $this->inside = true;

            try {
                return $callback();
            } finally {
                $this->inside = false;
            }
        }
    };
    $teams = Mockery::mock(ManagesTeams::class);
    $teams->shouldReceive('membershipForUpdate')->once()->with(7, 3)
        ->andReturnUsing(function () use ($transactions): TeamMembershipData {
            expect($transactions->inside)->toBeTrue();

            return new TeamMembershipData(7, false, 3, TeamRole::Member, null, null, []);
        });
    $teams->shouldNotReceive('update');

    try {
        expect(fn () => (new UpdateTeamHandler($teams, $transactions, new TeamLifecyclePolicy))
            ->handle(new UpdateTeam(3, 7, 'Changed')))->toThrow(TeamOperationRejected::class);
    } finally {
        Mockery::close();
    }
});

it('persists a permitted update inside the transaction', function (): void {
    $transactions = new class implements RunsTeamTransactions
    {
        public function run(Closure $callback): mixed
        {
            return $callback();
        }
    };
    $teams = Mockery::mock(ManagesTeams::class);
    $teams->shouldReceive('membershipForUpdate')->once()->with(7, 3)
        ->andReturn(new TeamMembershipData(7, false, 3, TeamRole::Owner, null, null, []));
    $teams->shouldReceive('update')->once()->with(7, 'Changed')->andReturn(new TeamData(7, 'Changed', 'changed', false));

    try {
        $updated = (new UpdateTeamHandler($teams, $transactions, new TeamLifecyclePolicy))
            ->handle(new UpdateTeam(3, 7, 'Changed'));
        expect($updated->name)->toBe('Changed');
    } finally {
        Mockery::close();
    }
});
