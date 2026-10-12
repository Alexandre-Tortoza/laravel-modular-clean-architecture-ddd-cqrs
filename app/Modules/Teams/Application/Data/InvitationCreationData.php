<?php

namespace App\Modules\Teams\Application\Data;

use App\Modules\Teams\Domain\Enums\TeamRole;
use DateTimeImmutable;

readonly class InvitationCreationData
{
    public function __construct(
        public ?TeamRole $inviterRole,
        public string $inviterName,
        public bool $recipientIsMember,
        /** @var list<?DateTimeImmutable> */
        public array $pendingExpiresAt,
    ) {}
}
