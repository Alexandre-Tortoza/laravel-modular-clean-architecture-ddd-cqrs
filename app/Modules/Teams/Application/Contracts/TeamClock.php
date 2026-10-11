<?php

namespace App\Modules\Teams\Application\Contracts;

use DateTimeImmutable;

interface TeamClock
{
    public function now(): DateTimeImmutable;
}
