<?php

namespace App\Modules\Teams\Presentation\Rules;

use App\Modules\Identity\Application\Api\UserDirectory;
use App\Modules\Teams\Application\Contracts\TeamClock;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Membership;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class UniqueTeamInvitation implements ValidationRule
{
    public function __construct(protected Team $team, protected TeamClock $clock, protected UserDirectory $users) {}

    /** @param Closure(string, ?string=): PotentiallyTranslatedString $fail */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = mb_strtolower((string) $value);
        $recipientIds = array_keys($this->users->findAllByEmail($email));

        if (Membership::query()->where('team_id', $this->team->id)->whereIn('user_id', $recipientIds)->exists()) {
            $fail(__('This user is already a member of the team.'));

            return;
        }

        $hasPendingInvitation = TeamInvitation::where('team_id', $this->team->id)
            ->whereRaw('LOWER(email) = ?', [$email])->whereNull('accepted_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $this->clock->now()))->exists();

        if ($hasPendingInvitation) {
            $fail(__('An invitation has already been sent to this email address.'));
        }
    }
}
