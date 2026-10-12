<?php

use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;
use Tests\Support\UserWithPersonalTeam;

test('expired invitations are deleted by the scheduled cleanup', function () {
    $this->travelTo(now()->startOfDay());

    $owner = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    $expiredInvitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'invited_by' => $owner->id,
    ]);

    $unexpiredInvitation = TeamInvitation::factory()->expiresIn(1)->create([
        'team_id' => $team->id,
        'invited_by' => $owner->id,
    ]);

    $invitationWithoutExpiration = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'invited_by' => $owner->id,
    ]);

    $this->artisan('schedule:run')->assertSuccessful();

    $this->assertDatabaseMissing('team_invitations', [
        'id' => $expiredInvitation->id,
    ]);

    $this->assertDatabaseHas('team_invitations', [
        'id' => $unexpiredInvitation->id,
    ]);

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitationWithoutExpiration->id,
    ]);
});
