<?php

namespace App\Modules\Teams\Domain\Policies;

class AccountTeamDeletionPolicy
{
    /** @param list<array{id: int, isPersonal: bool, memberCount: int}> $ownedTeams */
    public function rejectionMessage(array $ownedTeams): ?string
    {
        foreach ($ownedTeams as $team) {
            if (! $team['isPersonal']) {
                return 'Delete or transfer ownership of your shared teams before deleting your account.';
            }

            if ($team['memberCount'] > 1) {
                return 'Remove other members from your personal team before deleting your account.';
            }
        }

        return null;
    }
}
