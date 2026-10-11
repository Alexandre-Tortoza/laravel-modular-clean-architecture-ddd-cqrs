<?php

namespace App\Modules\Profile\Application\Contracts;

interface DeletesProfiles
{
    public function delete(int $userId): void;
}
