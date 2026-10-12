<?php

namespace App\Modules\Teams\Domain\Policies;

use App\Modules\Teams\Domain\Exceptions\InvalidTeamName;

use function trim;

class TeamNamePolicy
{
    public function assertValid(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidTeamName('The team name is required.');
        }

        if (mb_strlen($name) > 255) {
            throw new InvalidTeamName('The team name may not be greater than 255 characters.');
        }
    }
}
