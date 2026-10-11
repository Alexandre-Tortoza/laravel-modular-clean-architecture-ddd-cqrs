<?php

namespace App\Modules\Teams\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Teams\Application\Contracts\TeamQueries;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TeamQueries $teams): Response
    {
        $pendingInvitations = collect($teams->pendingInvitationsForEmail($request->user()->email))
            ->map(fn ($invitation): array => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviterName,
                'team' => [
                    'name' => $invitation->teamName,
                    'slug' => $invitation->teamSlug,
                ],
            ]);

        return Inertia::render('Dashboard', [
            'pendingInvitations' => $pendingInvitations,
        ]);
    }
}
