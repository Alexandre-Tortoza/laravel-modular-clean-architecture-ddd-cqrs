<?php

namespace App\Modules\Identity\Application\Api;

readonly class UserRecord
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public ?string $avatar,
    ) {}
}
