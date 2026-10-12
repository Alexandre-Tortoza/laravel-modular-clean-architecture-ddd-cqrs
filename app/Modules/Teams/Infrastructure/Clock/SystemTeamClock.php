<?php

namespace App\Modules\Teams\Infrastructure\Clock;

use App\Modules\Teams\Application\Contracts\TeamClock;
use DateTimeImmutable;

class SystemTeamClock implements TeamClock
{
    public function now(): DateTimeImmutable
    {
        return now()->toDateTimeImmutable();
    }
}
