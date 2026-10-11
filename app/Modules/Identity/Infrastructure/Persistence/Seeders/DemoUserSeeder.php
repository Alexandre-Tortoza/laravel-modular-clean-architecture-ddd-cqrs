<?php

namespace App\Modules\Identity\Infrastructure\Persistence\Seeders;

use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use Illuminate\Database\Seeder;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        if (User::query()->where('email', 'test@example.com')->exists()) {
            return;
        }

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
