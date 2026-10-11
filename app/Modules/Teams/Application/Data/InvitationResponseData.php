<?php

namespace App\Modules\Teams\Application\Data;

use App\Modules\Teams\Domain\Enums\TeamRole;
use DateTimeImmutable;

readonly class InvitationResponseData
{
    public function __construct(
        public int $invitationId,
        public int $teamId,
        public int $userId,
        public string $recipientEmail,
        public string $userEmail,
        public TeamRole $role,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $acceptedAt,
    ) {}
}
