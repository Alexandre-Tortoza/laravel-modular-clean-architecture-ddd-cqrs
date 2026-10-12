<?php

namespace App\Modules\Profile\Domain\Policies;

class EmailVerificationPolicy
{
    public function shouldInvalidate(string $currentEmail, string $newEmail): bool
    {
        return $currentEmail !== $newEmail;
    }
}
