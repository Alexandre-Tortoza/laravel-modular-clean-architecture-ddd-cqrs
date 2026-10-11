<?php

use App\Modules\Teams\Domain\Enums\TeamPermission;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Domain\Exceptions\TeamInvitationCreationRejected;
use App\Modules\Teams\Domain\Exceptions\TeamInvitationResponseRejected;
use App\Modules\Teams\Domain\Exceptions\TeamOperationRejected;
use App\Modules\Teams\Domain\Policies\InvitationPolicy;
use App\Modules\Teams\Domain\Policies\TeamLifecyclePolicy;

it('rejects an owner invitation and a duplicate pending recipient without Laravel boot', function (): void {
    $policy = new InvitationPolicy;

    expect(fn () => $policy->assertCanCreate(TeamRole::Owner, TeamRole::Owner, false, false))
        ->toThrow(TeamOperationRejected::class);
    expect(fn () => $policy->assertCanCreate(TeamRole::Owner, TeamRole::Member, true, false))
        ->toThrow(TeamInvitationCreationRejected::class);
    expect(fn () => $policy->assertCanCreate(TeamRole::Owner, TeamRole::Member, false, true))
        ->toThrow(TeamInvitationCreationRejected::class);
});

it('rejects an invitation at its expiration instant without Laravel boot', function (): void {
    $policy = new InvitationPolicy;
    $now = new DateTimeImmutable('2026-01-04 12:00:00');

    expect(fn () => $policy->assertCanRespond('user@example.com', 'USER@example.com', $now, null, $now))
        ->toThrow(TeamInvitationResponseRejected::class);
});

it('protects owner roles and personal teams without Laravel boot', function (): void {
    $policy = new TeamLifecyclePolicy;

    expect(fn () => $policy->assertCanChangeRole(TeamRole::Owner, TeamRole::Owner, TeamRole::Member))
        ->toThrow(TeamOperationRejected::class);
    expect(fn () => $policy->assertCanLeave(true, TeamRole::Owner))
        ->toThrow(TeamOperationRejected::class);
    expect(fn () => $policy->assertCanDelete(false, TeamRole::Member))
        ->toThrow(TeamOperationRejected::class);
    expect(fn () => $policy->assertPermission(TeamRole::Member, TeamPermission::UpdateTeam))
        ->toThrow(TeamOperationRejected::class);
});

it('rejects assigning the owner role to a different member', function (): void {
    expect(fn () => (new TeamLifecyclePolicy)->assertCanChangeRole(TeamRole::Owner, TeamRole::Member, TeamRole::Owner))
        ->toThrow(TeamOperationRejected::class, 'The team owner role cannot be assigned.');
});

it('has no fallback when the selected team is the only available team', function (): void {
    expect((new TeamLifecyclePolicy)->fallbackTeamId(7, [7]))->toBeNull();
});

it('permits a fresh member invitation with no conflicting membership', function (): void {
    $policy = new InvitationPolicy;

    expect(fn () => $policy->assertCanCreate(TeamRole::Owner, TeamRole::Member, false, false))->not->toThrow(Throwable::class);
    expect(fn () => $policy->assertCanCreate(TeamRole::Owner, TeamRole::Admin, false, false))->not->toThrow(Throwable::class);
});
