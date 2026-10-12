<?php

namespace App\Modules\Identity\Domain\Policies;

class EmailVerificationPolicy
{
    public function shouldInvalidate(string $currentEmail, string $newEmail): bool
    {
        return $currentEmail !== $newEmail;
    }
}
