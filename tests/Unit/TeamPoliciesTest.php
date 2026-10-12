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
    $now = new DateTimeImmutable('2026-01-04 12:00:00');

    expect(fn () => $policy->assertCanCreate(TeamRole::Owner, TeamRole::Owner, false, [], $now))
        ->toThrow(TeamOperationRejected::class);
    expect(fn () => $policy->assertCanCreate(TeamRole::Owner, TeamRole::Member, true, [], $now))
        ->toThrow(TeamInvitationCreationRejected::class);
    expect(fn () => $policy->assertCanCreate(TeamRole::Owner, TeamRole::Member, false, [null], $now))
        ->toThrow(TeamInvitationCreationRejected::class);
});

it('rejects an invitation at its expiration instant without Laravel boot', function (): void {
    $policy = new InvitationPolicy;
    $now = new DateTimeImmutable('2026-01-04 12:00:00');

    expect(fn () => $policy->assertCanRespond('user@example.com', 'USER@example.com', $now, null, $now))
        ->toThrow(TeamInvitationResponseRejected::class);
});

it('accepts a new invitation when earlier invitations expired at the current instant', function (): void {
    $policy = new InvitationPolicy;
    $now = new DateTimeImmutable('2026-01-04 12:00:00');

    expect(fn () => $policy->assertCanCreate(TeamRole::Owner, TeamRole::Member, false, [$now], $now))
        ->not->toThrow(Throwable::class);
    expect(fn () => $policy->assertCanCreate(TeamRole::Owner, TeamRole::Member, false, [$now->modify('+1 second')], $now))
        ->toThrow(TeamInvitationCreationRejected::class);
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
    $now = new DateTimeImmutable('2026-01-04 12:00:00');

    expect(fn () => $policy->assertCanCreate(TeamRole::Owner, TeamRole::Member, false, [], $now))->not->toThrow(Throwable::class);
    expect(fn () => $policy->assertCanCreate(TeamRole::Owner, TeamRole::Admin, false, [], $now))->not->toThrow(Throwable::class);
});
