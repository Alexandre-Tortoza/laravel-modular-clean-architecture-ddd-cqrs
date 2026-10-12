<?php

use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;

test('creating a user does not create team data', function () {
    $user = User::factory()->create();

    expect($user->exists)->toBeTrue()
        ->and(Team::query()->count())->toBe(0);
});
