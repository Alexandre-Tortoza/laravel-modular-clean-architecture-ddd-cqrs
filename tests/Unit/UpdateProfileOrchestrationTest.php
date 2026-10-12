<?php

use App\Modules\Identity\Application\Api\ProfileAccounts;
use App\Modules\Profile\Application\Commands\UpdateProfile;
use App\Modules\Profile\Application\Commands\UpdateProfileHandler;

test('profile handler delegates the actor intent to the protected Identity operation', function () {
    $accounts = Mockery::mock(ProfileAccounts::class);
    $accounts->shouldReceive('updateOwnProfile')->once()
        ->with(12, 'Changed', 'after@example.com');

    (new UpdateProfileHandler($accounts))
        ->handle(new UpdateProfile(12, 'Changed', 'after@example.com'));
});
