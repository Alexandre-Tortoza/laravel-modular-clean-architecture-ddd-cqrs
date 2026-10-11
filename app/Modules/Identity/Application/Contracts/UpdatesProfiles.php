<?php

namespace App\Modules\Identity\Application\Contracts;

use App\Modules\Identity\Application\Data\ProfileData;

interface UpdatesProfiles
{
    public function findForUpdate(int $userId): ProfileData;

    public function update(int $userId, string $name, string $email, bool $invalidateEmailVerification): void;
}
