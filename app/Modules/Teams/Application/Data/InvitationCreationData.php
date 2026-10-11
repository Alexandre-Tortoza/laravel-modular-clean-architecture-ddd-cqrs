<?php

namespace App\Modules\Teams\Application\Data;

use App\Modules\Teams\Domain\Enums\TeamRole;

readonly class InvitationCreationData
{
    public function __construct(
        public ?TeamRole $inviterRole,
        public string $inviterName,
        public bool $recipientIsMember,
        public bool $hasPendingInvitation,
    ) {}
}
