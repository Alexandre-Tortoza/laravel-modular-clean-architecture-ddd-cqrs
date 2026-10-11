<?php

namespace App\Modules\Teams\Application\Data;

readonly class PendingTeamInvitationData
{
    public function __construct(
        public string $code,
        public string $teamName,
        public string $teamSlug,
        public ?string $inviterName = null,
    ) {}
}
