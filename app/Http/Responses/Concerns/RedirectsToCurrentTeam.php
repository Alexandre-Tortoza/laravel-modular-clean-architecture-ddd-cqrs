<?php

namespace App\Http\Responses\Concerns;

use App\Modules\Teams\Application\Api\TeamContext;
use App\Modules\Teams\Application\Data\TeamData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

trait RedirectsToCurrentTeam
{
    protected function redirectPathForCurrentTeam(Request $request, string $redirect): string
    {
        $team = $this->currentTeam($request);

        if ($team === null) {
            return route('teams.index', absolute: false);
        }

        URL::defaults(['current_team' => $team->slug]);

        return "/{$team->slug}{$redirect}";
    }

    protected function currentTeam(Request $request): ?TeamData
    {
        $user = $request->user();

        abort_if(! $user, 403);

        $team = app(TeamContext::class)->currentOrPersonalForUser($user->id);

        return $team;
    }
}
