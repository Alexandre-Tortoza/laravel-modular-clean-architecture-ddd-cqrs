<?php

namespace App\Modules\Teams\Presentation\Policies;

use App\Modules\Teams\Application\Api\TeamContext;
use App\Modules\Teams\Domain\Enums\TeamPermission;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use Illuminate\Contracts\Auth\Authenticatable;

class TeamPolicy
{
    public function __construct(private TeamContext $teams) {}

    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

    public function view(Authenticatable $user, Team $team): bool
    {
        return $this->teams->belongsToTeam((int) $user->getAuthIdentifier(), $team->id);
    }

    public function create(Authenticatable $user): bool
    {
        return true;
    }

    public function update(Authenticatable $user, Team $team): bool
    {
        return $this->teams->hasPermission((int) $user->getAuthIdentifier(), $team->id, TeamPermission::UpdateTeam);
    }

    public function leave(Authenticatable $user, Team $team): bool
    {
        $userId = (int) $user->getAuthIdentifier();

        return ! $team->is_personal && $this->teams->belongsToTeam($userId, $team->id)
            && ! $this->teams->ownsTeam($userId, $team->id);
    }

    public function addMember(Authenticatable $user, Team $team): bool
    {
        return $this->teams->hasPermission((int) $user->getAuthIdentifier(), $team->id, TeamPermission::AddMember);
    }

    public function updateMember(Authenticatable $user, Team $team): bool
    {
        return $this->teams->hasPermission((int) $user->getAuthIdentifier(), $team->id, TeamPermission::UpdateMember);
    }

    public function removeMember(Authenticatable $user, Team $team): bool
    {
        return $this->teams->hasPermission((int) $user->getAuthIdentifier(), $team->id, TeamPermission::RemoveMember);
    }

    public function inviteMember(Authenticatable $user, Team $team): bool
    {
        return $this->teams->hasPermission((int) $user->getAuthIdentifier(), $team->id, TeamPermission::CreateInvitation);
    }

    public function cancelInvitation(Authenticatable $user, Team $team): bool
    {
        return $this->teams->hasPermission((int) $user->getAuthIdentifier(), $team->id, TeamPermission::CancelInvitation);
    }

    public function delete(Authenticatable $user, Team $team): bool
    {
        return ! $team->is_personal && $this->teams->hasPermission((int) $user->getAuthIdentifier(), $team->id, TeamPermission::DeleteTeam);
    }
}
