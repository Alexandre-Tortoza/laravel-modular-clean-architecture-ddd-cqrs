<?php

namespace Database\Seeders;

use App\Modules\Identity\Infrastructure\Persistence\Seeders\DemoUserSeeder;
use App\Modules\Teams\Infrastructure\Persistence\Seeders\DemoPersonalTeamSeeder;
use Illuminate\Database\Seeder;
use LogicException;

class DemoDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo data may only be seeded in local or testing environments.');
        }

        $this->call([
            DemoUserSeeder::class,
            DemoPersonalTeamSeeder::class,
        ]);
    }
}
