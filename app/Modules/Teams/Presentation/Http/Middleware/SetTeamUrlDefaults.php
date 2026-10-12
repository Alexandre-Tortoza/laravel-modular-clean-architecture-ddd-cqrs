<?php

namespace App\Modules\Teams\Presentation\Http\Middleware;

use App\Modules\Teams\Application\Api\TeamContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetTeamUrlDefaults
{
    public function __construct(private TeamContext $teams) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->user()?->id;
        $currentTeam = $userId === null ? null : $this->teams->currentForUser($userId);

        if ($currentTeam !== null) {
            URL::defaults(['current_team' => $currentTeam->slug, 'team' => $currentTeam->slug]);
        }

        return $next($request);
    }
}
