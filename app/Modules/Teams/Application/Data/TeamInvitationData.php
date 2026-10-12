<?php

namespace App\Modules\Teams\Application\Data;

use App\Modules\Teams\Domain\Enums\TeamRole;

readonly class TeamInvitationData
{
    public function __construct(public int $id, public string $code, public string $email, public int $teamId, public string $teamName, public string $inviterName, public TeamRole $role) {}
}
