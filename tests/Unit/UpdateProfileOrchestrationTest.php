<?php

use App\Modules\Identity\Application\Api\ProfileAccounts;
use App\Modules\Identity\Application\Api\ProfileAccountSnapshot;
use App\Modules\Profile\Application\Commands\UpdateProfile;
use App\Modules\Profile\Application\Commands\UpdateProfileHandler;
use App\Modules\Profile\Application\Contracts\RunsProfileTransactions;
use App\Modules\Profile\Domain\Policies\EmailVerificationPolicy;

test('profile handler locks and saves the actor inside one transaction', function () {
    $accounts = Mockery::mock(ProfileAccounts::class);
    $accounts->shouldReceive('lockOwnProfile')->once()->with(12)
        ->andReturn(new ProfileAccountSnapshot(12, 'before@example.com'));
    $accounts->shouldReceive('saveOwnProfile')->once()
        ->with(12, 'Changed', 'after@example.com', true);
    $transactions = Mockery::mock(RunsProfileTransactions::class);
    $transactions->shouldReceive('run')->once()->andReturnUsing(fn ($callback) => $callback());

    (new UpdateProfileHandler($accounts, $transactions, new EmailVerificationPolicy))
        ->handle(new UpdateProfile(12, 'Changed', 'after@example.com'));
});
