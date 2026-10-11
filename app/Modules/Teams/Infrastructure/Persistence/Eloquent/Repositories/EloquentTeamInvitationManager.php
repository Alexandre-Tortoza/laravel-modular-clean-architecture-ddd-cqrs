<?php

namespace App\Modules\Teams\Infrastructure\Persistence\Eloquent\Repositories;

use App\Models\User;
use App\Modules\Teams\Application\Contracts\ManagesTeamInvitations;
use App\Modules\Teams\Application\Contracts\TeamClock;
use App\Modules\Teams\Application\Data\InvitationResponseData;
use App\Modules\Teams\Application\Data\TeamInvitationData;
use App\Modules\Teams\Application\Exceptions\TeamInvitationCreationRejected;
use App\Modules\Teams\Application\Exceptions\TeamOperationRejected;
use App\Modules\Teams\Domain\Enums\TeamPermission;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;

class EloquentTeamInvitationManager implements ManagesTeamInvitations
{
    public function __construct(private TeamClock $clock) {}

    public function create(int $teamId, int $inviterId, string $email, TeamRole $role): TeamInvitationData
    {
        $team = Team::query()->lockForUpdate()->findOrFail($teamId);
        $normalizedEmail = mb_strtolower($email);
        $pendingInvitations = $team->invitations()
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->whereNull('accepted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $recipientIds = User::query()->whereRaw('LOWER(email) = ?', [$normalizedEmail])->pluck('id')->all();
        $users = $this->lockUsers([...$recipientIds, $inviterId]);
        $this->assertPermission($team, $users[$inviterId], TeamPermission::CreateInvitation);

        if (! in_array($role, [TeamRole::Admin, TeamRole::Member], true)) {
            throw new TeamOperationRejected('The owner role cannot be assigned through an invitation.');
        }

        foreach ($recipientIds as $recipientId) {
            if ($team->memberships()->where('user_id', $recipientId)->exists()) {
                throw new TeamInvitationCreationRejected('This user is already a member of the team.');
            }
        }

        if ($pendingInvitations->contains(fn (TeamInvitation $invitation): bool => $invitation->expires_at === null || $invitation->expires_at->toDateTimeImmutable() > $this->clock->now())) {
            throw new TeamInvitationCreationRejected('An invitation has already been sent to this email address.');
        }

        $invitation = $team->invitations()->create([
            'email' => $email,
            'role' => $role,
            'invited_by' => $inviterId,
            'expires_at' => $this->clock->now()->modify('+3 days'),
        ]);

        return new TeamInvitationData($invitation->id, $invitation->code, $invitation->email, $team->id, $team->name, $users[$inviterId]->name, $invitation->role);
    }

    public function cancel(int $teamId, int $actorId, int $invitationId): void
    {
        $team = Team::query()->lockForUpdate()->findOrFail($teamId);
        $invitation = $team->invitations()->lockForUpdate()->findOrFail($invitationId);
        $users = $this->lockUsers([$actorId]);
        $this->assertPermission($team, $users[$actorId], TeamPermission::CancelInvitation);

        $invitation->delete();
    }

    public function responseForUpdate(int $userId, int $invitationId): InvitationResponseData
    {
        $teamId = TeamInvitation::query()->findOrFail($invitationId)->team_id;
        $team = Team::query()->lockForUpdate()->findOrFail($teamId);
        $invitation = $team->invitations()->lockForUpdate()->findOrFail($invitationId);
        $users = $this->lockUsers([$userId]);
        $user = $users[$userId];

        return new InvitationResponseData(
            $invitation->id,
            $team->id,
            $team->slug,
            $user->id,
            $invitation->email,
            $user->email,
            $invitation->role,
            $invitation->expires_at?->toDateTimeImmutable(),
            $invitation->accepted_at?->toDateTimeImmutable(),
        );
    }

    public function accept(InvitationResponseData $invitation): string
    {
        Team::query()->findOrFail($invitation->teamId)->memberships()->firstOrCreate(
            ['user_id' => $invitation->userId],
            ['role' => $invitation->role],
        );

        TeamInvitation::query()->findOrFail($invitation->invitationId)
            ->update(['accepted_at' => $this->clock->now()]);

        User::query()->whereKey($invitation->userId)
            ->update(['current_team_id' => $invitation->teamId]);

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

    /**
     * @param  array<int>  $userIds
     * @return array<int, User>
     */
    private function lockUsers(array $userIds): array
    {
        $lockedUsers = [];
        $userIds = array_unique($userIds);
        sort($userIds);

        foreach ($userIds as $userId) {
            $lockedUsers[$userId] = User::query()->lockForUpdate()->findOrFail($userId);
        }

        return $lockedUsers;
    }

    private function assertPermission(Team $team, User $actor, TeamPermission $permission): void
    {
        $membership = $team->memberships()->where('user_id', $actor->id)->first();

        if (! $membership?->role->hasPermission($permission)) {
            throw new TeamOperationRejected('You are not allowed to manage invitations for this team.');
        }
    }
}
