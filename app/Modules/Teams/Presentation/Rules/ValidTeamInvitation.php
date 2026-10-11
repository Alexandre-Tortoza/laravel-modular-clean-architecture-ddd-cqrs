<?php

namespace App\Modules\Teams\Presentation\Rules;

use App\Modules\Identity\Application\Api\UserDirectory;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidTeamInvitation implements ValidationRule
{
    public function __construct(protected ?Authenticatable $user, protected UserDirectory $users) {}

    /** @param Closure(string, ?string=): PotentiallyTranslatedString $fail */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof TeamInvitation || $this->user === null) {
            $fail(__('This invitation was sent to a different email address.'));

            return;
        }

        if ($value->isAccepted()) {
            $fail(__('This invitation has already been accepted.'));

            return;
        }

        if ($value->isExpired()) {
            $fail(__('This invitation has expired.'));

            return;
        }

        $email = $this->users->find((int) $this->user->getAuthIdentifier())?->email;

        if ($email === null || mb_strtolower($value->email) !== mb_strtolower($email)) {
            $fail(__('This invitation was sent to a different email address.'));
        }
    }
}
