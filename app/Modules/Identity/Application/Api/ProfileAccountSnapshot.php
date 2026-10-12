<?php

namespace App\Modules\Identity\Application\Api;

readonly class ProfileAccountSnapshot
{
    public function __construct(public int $id, public string $email) {}
}
