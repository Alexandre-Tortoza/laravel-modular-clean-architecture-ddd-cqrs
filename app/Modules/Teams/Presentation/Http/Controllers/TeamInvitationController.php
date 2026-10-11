<?php

namespace App\Modules\Teams\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Teams\Application\Commands\AcceptTeamInvitation;
use App\Modules\Teams\Application\Commands\AcceptTeamInvitationHandler;
use App\Modules\Teams\Application\Commands\CancelTeamInvitation;
use App\Modules\Teams\Application\Commands\CancelTeamInvitationHandler;
use App\Modules\Teams\Application\Commands\CreateTeamInvitation;
use App\Modules\Teams\Application\Commands\CreateTeamInvitationHandler;
use App\Modules\Teams\Application\Commands\DeclineTeamInvitation;
use App\Modules\Teams\Application\Commands\DeclineTeamInvitationHandler;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Domain\Exceptions\TeamInvitationCreationRejected;
use App\Modules\Teams\Domain\Exceptions\TeamInvitationResponseRejected;
use App\Modules\Teams\Domain\Exceptions\TeamOperationRejected;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;
use App\Modules\Teams\Presentation\Http\Requests\CreateTeamInvitationRequest;
use App\Modules\Teams\Presentation\Http\Requests\RespondToTeamInvitationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TeamInvitationController extends Controller
{
    /**
     * Store a newly created invitation.
     */
    public function store(CreateTeamInvitationRequest $request, Team $team, CreateTeamInvitationHandler $handler): RedirectResponse
    {
        Gate::authorize('inviteMember', $team);

        try {
            $handler->handle(new CreateTeamInvitation(
                teamId: $team->id,
                inviterId: $request->user()->id,
                email: $request->validated('email'),
                role: TeamRole::from($request->validated('role')),
            ));
        } catch (TeamInvitationCreationRejected $exception) {
            throw ValidationException::withMessages(['email' => $exception->getMessage()]);
        } catch (TeamOperationRejected $exception) {
            abort(403, $exception->getMessage());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Cancel the specified invitation.
     */
    public function destroy(Request $request, Team $team, TeamInvitation $invitation, CancelTeamInvitationHandler $handler): RedirectResponse
    {
        abort_unless($invitation->team_id === $team->id, 404);

        Gate::authorize('cancelInvitation', $team);

        try {
            $handler->handle(new CancelTeamInvitation($team->id, $request->user()->id, $invitation->id));
        } catch (TeamOperationRejected $exception) {
            abort(403, $exception->getMessage());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Accept the invitation.
     */
    public function accept(RespondToTeamInvitationRequest $request, TeamInvitation $invitation, AcceptTeamInvitationHandler $handler): RedirectResponse
    {
        try {
            $teamSlug = $handler->handle(new AcceptTeamInvitation($request->user()->id, $invitation->id));
        } catch (TeamInvitationResponseRejected $exception) {
            throw ValidationException::withMessages(['invitation' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation accepted.')]);

        return to_route('dashboard', ['current_team' => $teamSlug]);
    }

    /**
     * Decline the invitation.
     */
    public function decline(RespondToTeamInvitationRequest $request, TeamInvitation $invitation, DeclineTeamInvitationHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new DeclineTeamInvitation($request->user()->id, $invitation->id));
        } catch (TeamInvitationResponseRejected $exception) {
            throw ValidationException::withMessages(['invitation' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation declined.')]);

        return to_route('dashboard');
    }
}
