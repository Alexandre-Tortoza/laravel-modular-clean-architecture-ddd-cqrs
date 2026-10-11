<?php

use App\Modules\Identity\Application\Api\UserDirectory;
use App\Modules\Teams\Application\Api\TeamContext;
use App\Modules\Teams\Application\Data\TeamInvitationData;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Notifications\TeamInvitation as TeamInvitationNotification;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;
use App\Modules\Teams\Presentation\Rules\ValidTeamInvitation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Tests\Support\UserWithPersonalTeam;

test('team invitations can be created', function () {
    Notification::fake();

    $owner = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    $response = $this
        ->actingAs($owner)
        ->post(route('teams.invitations.store', $team), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Member->value,
        ]);

    $response->assertRedirect(route('teams.edit', $team));

    $this->assertDatabaseHas('team_invitations', [
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Member->value,
    ]);

    Notification::assertSentOnDemandOnce(TeamInvitationNotification::class);
});

test('owner role cannot be assigned through an invitation request', function () {
    Notification::fake();

    $owner = UserWithPersonalTeam::create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    $this->actingAs($owner)
        ->post(route('teams.invitations.store', $team), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Owner->value,
        ])
        ->assertSessionHasErrors('role');

    $this->assertDatabaseMissing('team_invitations', ['team_id' => $team->id, 'email' => 'invited@example.com']);
    Notification::assertNothingSent();
});

test('invitation email for existing users uses login route', function () {
    $owner = UserWithPersonalTeam::create();
    $invitedUser = UserWithPersonalTeam::create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $invitedUser->email,
        'invited_by' => $owner->id,
    ]);

    $mail = (new TeamInvitationNotification(new TeamInvitationData($invitation->id, $invitation->code, $invitation->email, $invitation->team_id, $team->name, $owner->name, $invitation->role)))->toMail($invitedUser);

    expect($mail->actionUrl)->toBe(route('login', ['invitation' => $invitation->code]));
    $this->assertStringContainsString('dashboard', implode(' ', $mail->introLines));
});

test('invitation email for unknown users uses login route', function () {
    $owner = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'unknown@example.com',
        'invited_by' => $owner->id,
    ]);

    $mail = (new TeamInvitationNotification(new TeamInvitationData($invitation->id, $invitation->code, $invitation->email, $invitation->team_id, $team->name, $owner->name, $invitation->role)))->toMail((object) []);

    expect($mail->actionUrl)->toBe(route('login', ['invitation' => $invitation->code]));
    $this->assertStringContainsString('log in', strtolower(implode(' ', $mail->introLines)));
});

test('team invitations can be created by admins', function () {
    Notification::fake();

    $owner = UserWithPersonalTeam::create();
    $admin = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $team->memberships()->create(['user_id' => $admin->id, 'role' => TeamRole::Admin]);

    $response = $this
        ->actingAs($admin)
        ->post(route('teams.invitations.store', $team), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Member->value,
        ]);

    $response->assertRedirect(route('teams.edit', $team));
});

test('existing team members cannot be invited', function () {
    Notification::fake();

    $owner = UserWithPersonalTeam::create();
    $member = UserWithPersonalTeam::create(['email' => 'member@example.com']);
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $response = $this
        ->actingAs($owner)
        ->post(route('teams.invitations.store', $team), [
            'email' => 'member@example.com',
            'role' => TeamRole::Member->value,
        ]);

    $response->assertSessionHasErrors('email');
});

test('duplicate invitations cannot be created', function () {
    Notification::fake();

    $owner = UserWithPersonalTeam::create();
    $team = Team::factory()->create();
    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($owner)
        ->post(route('teams.invitations.store', $team), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Member->value,
        ]);

    $response->assertSessionHasErrors('email');
});

test('team invitations cannot be created by members', function () {
    $owner = UserWithPersonalTeam::create();
    $member = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $team->memberships()->create(['user_id' => $member->id, 'role' => TeamRole::Member]);

    $response = $this
        ->actingAs($member)
        ->post(route('teams.invitations.store', $team), [
            'email' => 'invited@example.com',
            'role' => TeamRole::Member->value,
        ]);

    $response->assertForbidden();
});

test('team invitations can be cancelled by owners', function () {
    $owner = UserWithPersonalTeam::create();
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('teams.invitations.destroy', [$team, $invitation]));

    $response->assertRedirect(route('teams.edit', $team));

    $this->assertDatabaseMissing('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('team invitations can be accepted', function () {
    $owner = UserWithPersonalTeam::create();
    $invitedUser = UserWithPersonalTeam::create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'role' => TeamRole::Member,
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->post(route('invitations.accept', $invitation));

    $response->assertRedirect(route('dashboard', ['current_team' => $team->slug]));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Invitation accepted.']);

    expect(app(TeamContext::class)->belongsToTeam($invitedUser->id, $team->id))->toBeTrue();
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('accepted team remains selected after following the redirect', function () {
    $owner = UserWithPersonalTeam::create();
    $recipient = UserWithPersonalTeam::create(['email' => 'invited@example.com']);
    $originalTeam = UserWithPersonalTeam::personalTeam($recipient);
    $invitedTeam = Team::factory()->create();
    $invitedTeam->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $invitedTeam->id,
        'email' => $recipient->email,
        'invited_by' => $owner->id,
    ]);

    $response = $this->actingAs($recipient)->post(route('invitations.accept', $invitation));
    $response->assertRedirect(route('dashboard', ['current_team' => $invitedTeam->slug]));
    $this->get($response->headers->get('Location'))->assertOk();

    expect($originalTeam->id)->not->toBe($invitedTeam->id);
    expect($recipient->fresh()->current_team_id)->toBe($invitedTeam->id);
});

test('team invitations can be declined by the invited user', function () {
    $owner = UserWithPersonalTeam::create();
    $invitedUser = UserWithPersonalTeam::create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertRedirect(route('dashboard'));

    $this->assertDatabaseMissing('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('team invitations cannot be declined by uninvited user', function () {
    $owner = UserWithPersonalTeam::create();
    $uninvitedUser = UserWithPersonalTeam::create(['email' => 'uninvited@example.com']);
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($uninvitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertSessionHasErrors('invitation');

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('accepted team invitations cannot be declined', function () {
    $owner = UserWithPersonalTeam::create();
    $invitedUser = UserWithPersonalTeam::create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    $invitation = TeamInvitation::factory()->accepted()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertSessionHasErrors('invitation');

    $this->assertDatabaseHas('team_invitations', [
        'id' => $invitation->id,
    ]);
});

test('team invitations cannot be accepted by uninvited user', function () {
    $owner = UserWithPersonalTeam::create();
    $uninvitedUser = UserWithPersonalTeam::create(['email' => 'uninvited@example.com']);
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($uninvitedUser)
        ->post(route('invitations.accept', $invitation));

    $response->assertSessionHasErrors('invitation');

    expect(app(TeamContext::class)->belongsToTeam($uninvitedUser->id, $team->id))->toBeFalse();
});

test('expired invitations cannot be accepted', function () {
    $owner = UserWithPersonalTeam::create();
    $invitedUser = UserWithPersonalTeam::create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();

    $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner]);

    $invitation = TeamInvitation::factory()->expired()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->post(route('invitations.accept', $invitation));

    $response->assertSessionHasErrors('invitation');

    expect(app(TeamContext::class)->belongsToTeam($invitedUser->id, $team->id))->toBeFalse();
});

test('invitation validation rejects invalid values and missing users', function () {
    $invitation = TeamInvitation::factory()->create(['invited_by' => UserWithPersonalTeam::create()->id]);

    $invalidValue = Validator::make(
        ['invitation' => 'not-an-invitation'],
        ['invitation' => [new ValidTeamInvitation(UserWithPersonalTeam::create(), app(UserDirectory::class))]],
    );
    $missingUser = Validator::make(
        ['invitation' => $invitation],
        ['invitation' => [new ValidTeamInvitation(null, app(UserDirectory::class))]],
    );

    expect($invalidValue->fails())->toBeTrue()
        ->and($invalidValue->errors()->first('invitation'))->toBe('This invitation was sent to a different email address.')
        ->and($missingUser->fails())->toBeTrue()
        ->and($missingUser->errors()->first('invitation'))->toBe('This invitation was sent to a different email address.');
});
