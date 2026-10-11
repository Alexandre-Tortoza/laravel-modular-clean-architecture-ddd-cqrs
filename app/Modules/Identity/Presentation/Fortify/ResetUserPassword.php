<?php

namespace App\Modules\Identity\Presentation\Fortify;

use App\Modules\Identity\Application\Commands\UpdatePassword;
use App\Modules\Identity\Application\Commands\UpdatePasswordHandler;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Identity\Presentation\Http\Concerns\PasswordValidationRules;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private UpdatePasswordHandler $updatePassword) {}

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $this->updatePassword->handle(new UpdatePassword($user->id, $input['password']));
    }
}
