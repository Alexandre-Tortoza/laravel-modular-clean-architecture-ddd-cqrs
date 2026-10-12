<?php

namespace App\Modules\Teams\Application\Api;

readonly class AccountTeamDeletionResult
{
    public function __construct(public bool $isAllowed, public ?string $message = null) {}
}
