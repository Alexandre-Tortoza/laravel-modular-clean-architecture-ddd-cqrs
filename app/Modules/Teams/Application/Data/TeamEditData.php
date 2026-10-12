<?php

namespace App\Modules\Teams\Application\Data;

readonly class TeamEditData
{
    /**
     * @param  list<array{id: int, name: string, email: string, avatar: ?string, role: string, role_label: string}>  $members
     * @param  list<array{code: string, email: string, role: string, role_label: string, created_at: string}>  $invitations
     */
    public function __construct(
        public TeamData $team,
        public array $members,
        public array $invitations,
        public TeamPermissionsData $permissions,
    ) {}
}
