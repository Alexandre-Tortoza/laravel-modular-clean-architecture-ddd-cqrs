<?php

use App\Modules\Identity\Application\Api\SecurityAccounts;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;

test('security account read exposes plain values for the actor', function () {
    $actor = User::factory()->create();

    expect(app(SecurityAccounts::class)->passkeysFor($actor->id))->toBe([])
        ->and(app(SecurityAccounts::class)->hasEnabledTwoFactorFor($actor->id))->toBeFalse();
});

test('security account read projects only the actor passkeys and two factor state', function () {
    $actor = User::factory()->withTwoFactor()->create();
    $other = User::factory()->create();
    $passkey = $actor->passkeys()->create([
        'name' => 'Laptop',
        'credential_id' => 'laptop-credential',
        'credential' => [],
    ]);
    $other->passkeys()->create([
        'name' => 'Other key',
        'credential_id' => 'other-credential',
        'credential' => [],
    ]);

    $accounts = app(SecurityAccounts::class);
    $passkeys = $accounts->passkeysFor($actor->id);

    expect($accounts->hasEnabledTwoFactorFor($actor->id))->toBeTrue()
        ->and($passkeys)->toHaveCount(1)
        ->and($passkeys[0]['id'])->toBe($passkey->id)
        ->and($passkeys[0]['name'])->toBe('Laptop')
        ->and($passkeys[0]['authenticator'])->toBeNull()
        ->and($passkeys[0]['created_at_diff'])->toBeString()
        ->and($passkeys[0]['last_used_at_diff'])->toBeNull();
});
