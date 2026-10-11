<?php

namespace App\Modules\Teams\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Teams\Application\Commands\AcceptTeamInvitation;
use App\Modules\Teams\Application\Commands\CancelTeamInvitation;
use App\Modules\Teams\Application\Commands\CreateTeamInvitation;
use App\Modules\Teams\Application\Commands\DeclineTeamInvitation;
use App\Modules\Teams\Application\Commands\TeamInvitationHandler;
use App\Modules\Teams\Application\Exceptions\TeamInvitationResponseRejected;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Notifications\TeamInvitation as TeamInvitationNotification;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;
use App\Modules\Teams\Presentation\Http\Requests\CreateTeamInvitationRequest;
use App\Modules\Teams\Presentation\Http\Requests\RespondToTeamInvitationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TeamInvitationController extends Controller
{
    /**
     * Store a newly created invitation.
     */
    public function store(CreateTeamInvitationRequest $request, Team $team, TeamInvitationHandler $handler): RedirectResponse
    {
        Gate::authorize('inviteMember', $team);

        $invitation = $handler->create(new CreateTeamInvitation(
            teamId: $team->id,
            inviterId: $request->user()->id,
            email: $request->validated('email'),
            role: TeamRole::from($request->validated('role')),
        ));

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Cancel the specified invitation.
     */
    public function destroy(Team $team, TeamInvitation $invitation, TeamInvitationHandler $handler): RedirectResponse
    {
        abort_unless($invitation->team_id === $team->id, 404);

        Gate::authorize('cancelInvitation', $team);

        $handler->cancel(new CancelTeamInvitation($team->id, $invitation->id));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Accept the invitation.
     */
    public function accept(RespondToTeamInvitationRequest $request, TeamInvitation $invitation, TeamInvitationHandler $handler): RedirectResponse
    {
        try {
            $handler->accept(new AcceptTeamInvitation($request->user()->id, $invitation->id));
        } catch (TeamInvitationResponseRejected $exception) {
            throw ValidationException::withMessages(['invitation' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation accepted.')]);

        return to_route('dashboard');
    }

    /**
     * Decline the invitation.
     */
    public function decline(RespondToTeamInvitationRequest $request, TeamInvitation $invitation, TeamInvitationHandler $handler): RedirectResponse
    {
        try {
            $handler->decline(new DeclineTeamInvitation($request->user()->id, $invitation->id));
        } catch (TeamInvitationResponseRejected $exception) {
            throw ValidationException::withMessages(['invitation' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation declined.')]);

        return to_route('dashboard');
    }
}
