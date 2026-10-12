<?php

use App\Modules\Identity\Application\Api\AccountCredentials;
use App\Modules\Identity\Application\Api\ProfileAccounts;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

test('profile account integration locks and saves only the actor within the caller transaction', function () {
    $actor = User::factory()->create();
    $other = User::factory()->create();

    $snapshot = DB::transaction(function () use ($actor) {
        $accounts = app(ProfileAccounts::class);
        $snapshot = $accounts->lockOwnProfile($actor->id);
        $accounts->saveOwnProfile($actor->id, 'Changed', 'changed@example.com', true);

        return $snapshot;
    });

    expect($snapshot->email)->toBe($actor->email)
        ->and($actor->fresh()->email_verified_at)->toBeNull()
        ->and($other->fresh()->email)->toBe($other->email);
});

test('credential operation changes only the actor password', function () {
    $actor = User::factory()->create();
    $other = User::factory()->create();

    app(AccountCredentials::class)->changeOwnPassword($actor->id, 'different-password');

    expect(Hash::check('different-password', $actor->fresh()->password))->toBeTrue()
        ->and(Hash::check('password', $other->fresh()->password))->toBeTrue();
});
