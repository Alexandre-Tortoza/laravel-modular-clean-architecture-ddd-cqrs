<?php

use App\Modules\Identity\Domain\Policies\EmailVerificationPolicy;

test('changing the email invalidates verification without Laravel boot', function () {
    $policy = new EmailVerificationPolicy;

    expect($policy->shouldInvalidate('first@example.com', 'first@example.com'))->toBeFalse()
        ->and($policy->shouldInvalidate('first@example.com', 'second@example.com'))->toBeTrue();
});
