<?php

namespace App\Modules\Identity\Application\Contracts;

interface DeletesProfiles
{
    public function delete(int $userId): void;
}
