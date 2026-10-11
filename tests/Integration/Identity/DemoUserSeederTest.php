<?php

use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Identity\Infrastructure\Persistence\Seeders\DemoUserSeeder;
use Illuminate\Support\Facades\DB;

test('demo user seeder creates only the identity user and is safe to rerun', function () {
    $this->seed(DemoUserSeeder::class);
    $this->seed(DemoUserSeeder::class);

    expect(User::query()->where('email', 'test@example.com')->count())->toBe(1)
        ->and(DB::table('teams')->count())->toBe(0);
});
