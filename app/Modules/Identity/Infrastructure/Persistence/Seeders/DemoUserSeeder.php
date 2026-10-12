<?php

namespace App\Modules\Identity\Infrastructure\Persistence\Seeders;

use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use Illuminate\Database\Seeder;
use LogicException;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo data may only be seeded in local or testing environments.');
        }

        if (User::query()->where('email', 'test@example.com')->exists()) {
            return;
        }

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
