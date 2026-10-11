<?php

use App\Models\User;
use App\Modules\Teams\Application\Data\TeamInvitationData;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Notifications\TeamInvitation as TeamInvitationNotification;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;
use Illuminate\Support\Facades\Schema;

test('configures factory states and membership relationships', function () {
    $user = User::factory()->create();
    $team = Team::factory()->personal()->create();
    $trashedTeam = Team::factory()->trashed()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $membership = $team->memberships()->firstOrFail();

    expect($team->is_personal)->toBeTrue()
        ->and($trashedTeam->trashed())->toBeTrue()
        ->and($membership->role)->toBe(TeamRole::Admin)
        ->and($membership->team->is($team))->toBeTrue()
        ->and($membership->user->is($user))->toBeTrue();
});

test('reports invitation lifecycle states', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $pending = TeamInvitation::factory()->create(['team_id' => $team->id, 'invited_by' => $owner->id]);
    $accepted = TeamInvitation::factory()->accepted()->create(['team_id' => $team->id, 'invited_by' => $owner->id]);
    $expired = TeamInvitation::factory()->expired()->create(['team_id' => $team->id, 'invited_by' => $owner->id]);
    $notification = new TeamInvitationNotification(new TeamInvitationData(
        $pending->id,
        $pending->code,
        $pending->email,
        $team->id,
        $team->name,
        $owner->name,
        $pending->role,
    ));

    expect($pending->isPending())->toBeTrue()
        ->and($pending->isAccepted())->toBeFalse()
        ->and($pending->isExpired())->toBeFalse()
        ->and($accepted->isAccepted())->toBeTrue()
        ->and($accepted->isPending())->toBeFalse()
        ->and($expired->isExpired())->toBeTrue()
        ->and($expired->isPending())->toBeFalse()
        ->and($pending->getRouteKeyName())->toBe('code')
        ->and($notification->via($owner))->toBe(['mail'])
        ->and($notification->toArray($owner))->toBe([
            'invitation_id' => $pending->id,
            'team_id' => $team->id,
            'team_name' => $team->name,
            'role' => TeamRole::Member->value,
        ]);
});

test('runs migrations in both directions and seeds the database', function () {
    $this->artisan('migrate:reset')->assertExitCode(0);

    expect(Schema::hasTable('users'))->toBeFalse();

    $this->artisan('migrate')->assertExitCode(0);
    $this->artisan('db:seed')->assertExitCode(0);

    expect(Schema::hasTable('agent_conversations'))->toBeTrue();
    $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
});
