<?php

namespace App\Modules\Teams\Infrastructure\Persistence\Eloquent\Repositories;

use App\Modules\Identity\Application\Api\TeamSelectionAccounts;
use App\Modules\Identity\Application\Api\UserDirectory;
use App\Modules\Teams\Application\Contracts\ManagesTeamInvitations;
use App\Modules\Teams\Application\Contracts\TeamClock;
use App\Modules\Teams\Application\Data\InvitationCreationData;
use App\Modules\Teams\Application\Data\InvitationResponseData;
use App\Modules\Teams\Application\Data\TeamInvitationData;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Membership;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;

class EloquentTeamInvitationManager implements ManagesTeamInvitations
{
    public function __construct(private TeamClock $clock, private UserDirectory $users, private TeamSelectionAccounts $selection) {}

    public function creationForUpdate(int $teamId, int $inviterId, string $email): InvitationCreationData
    {
        $team = Team::query()->lockForUpdate()->findOrFail($teamId);
        $normalizedEmail = mb_strtolower($email);
        $pending = $team->invitations()->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->whereNull('accepted_at')->orderBy('id')->lockForUpdate()->get();
        $recipients = $this->users->findAllByEmail($email);
        $recipientIds = array_keys($recipients);
        $lockedUsers = $this->selection->lockMany([...$recipientIds, $inviterId]);
        $inviterRole = Membership::query()->where('team_id', $teamId)->where('user_id', $inviterId)->first()?->role;
        $recipientIsMember = Membership::query()->where('team_id', $teamId)->whereIn('user_id', $recipientIds)->exists();
        $pendingExpiresAt = array_values($pending->map(
            fn (TeamInvitation $invitation): ?\DateTimeImmutable => $invitation->expires_at?->toDateTimeImmutable(),
        )->all());

        return new InvitationCreationData($inviterRole, $lockedUsers[$inviterId]->name, $recipientIsMember, $pendingExpiresAt);
    }

    public function create(int $teamId, int $inviterId, string $email, TeamRole $role, string $inviterName): TeamInvitationData
    {
        $team = Team::query()->findOrFail($teamId);
        $invitation = $team->invitations()->create([
            'email' => $email, 'role' => $role, 'invited_by' => $inviterId,
            'expires_at' => $this->clock->now()->modify('+3 days'),
        ]);

        return new TeamInvitationData($invitation->id, $invitation->code, $invitation->email,
            $team->id, $team->name, $inviterName, $invitation->role);
    }

    public function cancellationRoleForUpdate(int $teamId, int $actorId, int $invitationId): ?TeamRole
    {
        $team = Team::query()->lockForUpdate()->findOrFail($teamId);
        $team->invitations()->lockForUpdate()->findOrFail($invitationId);
        $this->selection->lockMany([$actorId]);

        return Membership::query()->where('team_id', $teamId)->where('user_id', $actorId)->first()?->role;
    }

    public function cancel(int $invitationId): void
    {
        TeamInvitation::query()->findOrFail($invitationId)->delete();
    }

    public function responseForUpdate(int $userId, int $invitationId): InvitationResponseData
    {
        $teamId = TeamInvitation::query()->findOrFail($invitationId)->team_id;
        $team = Team::query()->lockForUpdate()->findOrFail($teamId);
        $invitation = $team->invitations()->lockForUpdate()->findOrFail($invitationId);
        $user = $this->selection->lockMany([$userId])[$userId];

        return new InvitationResponseData($invitation->id, $team->id, $team->slug, $user->id,
            $invitation->email, $user->email, $invitation->role,
            $invitation->expires_at?->toDateTimeImmutable(), $invitation->accepted_at?->toDateTimeImmutable());
    }

    public function accept(InvitationResponseData $invitation): string
    {
        Team::query()->findOrFail($invitation->teamId)->memberships()->firstOrCreate(
            ['user_id' => $invitation->userId], ['role' => $invitation->role]);
        TeamInvitation::query()->findOrFail($invitation->invitationId)->update(['accepted_at' => $this->clock->now()]);
        $this->selection->select($invitation->userId, $invitation->teamId);

        return $invitation->teamSlug;
    }

    public function decline(int $invitationId): void
    {
        TeamInvitation::query()->findOrFail($invitationId)->delete();
    }

    public function pruneExpired(): void
    {
        TeamInvitation::query()->whereNotNull('expires_at')->where('expires_at', '<=', $this->clock->now())->delete();
    }
}
