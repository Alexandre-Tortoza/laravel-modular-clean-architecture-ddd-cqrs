<?php

namespace App\Modules\Profile\Application\Contracts;

use App\Modules\Profile\Application\Data\ProfileData;

interface UpdatesProfiles
{
    public function find(int $userId): ProfileData;

    public function update(int $userId, string $name, string $email, bool $invalidateEmailVerification): void;
}
