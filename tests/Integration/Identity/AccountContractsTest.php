<?php

use App\Modules\Identity\Application\Api\AccountCredentials;
use App\Modules\Identity\Application\Api\ProfileAccounts;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

test('profile account operation protects verification without a caller-managed transaction', function () {
    $actor = User::factory()->create(['email' => 'before@example.com', 'email_verified_at' => now()]);
    $other = User::factory()->create();

    app(ProfileAccounts::class)->updateOwnProfile($actor->id, 'Changed', 'changed@example.com');

    expect($actor->fresh()->name)->toBe('Changed')
        ->and($actor->fresh()->email_verified_at)->toBeNull()
        ->and($other->fresh()->email)->toBe($other->email);
});

test('profile account operation preserves verification for an unchanged email', function () {
    $actor = User::factory()->create(['email' => 'same@example.com', 'email_verified_at' => now()]);

    app(ProfileAccounts::class)->updateOwnProfile($actor->id, 'Changed', 'same@example.com');

    expect($actor->fresh()->email_verified_at)->not->toBeNull();
});

test('profile account operation joins an enclosing transaction', function () {
    $actor = User::factory()->create(['email' => 'before@example.com', 'email_verified_at' => now()]);

    try {
        DB::transaction(function () use ($actor): void {
            app(ProfileAccounts::class)->updateOwnProfile($actor->id, 'Changed', 'changed@example.com');
            throw new RuntimeException('abort');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('abort');
    }

    expect($actor->fresh()->email)->toBe('before@example.com')
        ->and($actor->fresh()->email_verified_at)->not->toBeNull();
});

test('credential operation changes only the actor password', function () {
    $actor = User::factory()->create();
    $other = User::factory()->create();

    app(AccountCredentials::class)->changeOwnPassword($actor->id, 'different-password');

    expect(Hash::check('different-password', $actor->fresh()->password))->toBeTrue()
        ->and(Hash::check('password', $other->fresh()->password))->toBeTrue();
});
