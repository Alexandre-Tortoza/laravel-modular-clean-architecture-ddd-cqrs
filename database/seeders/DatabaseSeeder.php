<?php

namespace Database\Seeders;

use App\Modules\Identity\Infrastructure\Persistence\Seeders\DemoUserSeeder;
use App\Modules\Teams\Infrastructure\Persistence\Seeders\DemoPersonalTeamSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            DemoUserSeeder::class,
            DemoPersonalTeamSeeder::class,
        ]);
    }
}
