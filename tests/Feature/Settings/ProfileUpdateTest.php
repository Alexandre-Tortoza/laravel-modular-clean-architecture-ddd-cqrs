<?php

use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\Profile\Presentation\Http\Concerns\ProfileValidationRules;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Tests\Support\UserWithPersonalTeam;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('a profile cannot take another account email', function () {
    $user = User::factory()->create();
    $other = User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'Changed', 'email' => $other->email])
        ->assertSessionHasErrors('email');

    expect($user->fresh()->email)->toBe($user->email);
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});

test('account deletion is rejected without logging out when the account owns a shared team', function () {
    $user = UserWithPersonalTeam::create();
    $sharedTeam = Team::factory()->create();
    $sharedTeam->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Owner]);
    $sharedTeam->memberships()->create(['user_id' => User::factory()->create()->id, 'role' => TeamRole::Member]);

    $response = $this->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password']);

    $response->assertSessionHasErrors('account')
        ->assertRedirect(route('profile.edit'));

    $this->assertAuthenticatedAs($user);
    $this->assertModelExists($user);
    $this->assertDatabaseHas('team_members', ['team_id' => $sharedTeam->id, 'user_id' => $user->id]);
});

test('account deletion is rejected when a personal team has another member', function () {
    $user = UserWithPersonalTeam::create();
    $personalTeam = UserWithPersonalTeam::personalTeam($user);
    $personalTeam->memberships()->create(['user_id' => User::factory()->create()->id, 'role' => TeamRole::Member]);

    $response = $this->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password']);

    $response->assertSessionHasErrors('account')
        ->assertRedirect(route('profile.edit'));

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('teams', ['id' => $personalTeam->id, 'deleted_at' => null]);
});

test('account deletion closes a sole personal team and revokes linked invitations', function () {
    $user = UserWithPersonalTeam::create(['email' => 'deleting@example.com']);
    $personalTeam = UserWithPersonalTeam::personalTeam($user);
    $otherOwner = User::factory()->create();
    $sharedTeam = Team::factory()->create();
    $sharedTeam->memberships()->create(['user_id' => $otherOwner->id, 'role' => TeamRole::Owner]);
    $sharedTeam->memberships()->create(['user_id' => $user->id, 'role' => TeamRole::Member]);
    $sentInvitation = TeamInvitation::factory()->create([
        'team_id' => $sharedTeam->id,
        'email' => 'sent@example.com',
        'invited_by' => $user->id,
    ]);
    $receivedInvitation = TeamInvitation::factory()->create([
        'team_id' => $sharedTeam->id,
        'email' => $user->email,
        'invited_by' => $otherOwner->id,
    ]);

    $response = $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password']);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    $this->assertModelMissing($user);
    $this->assertSoftDeleted('teams', ['id' => $personalTeam->id]);
    $this->assertDatabaseMissing('team_members', ['team_id' => $sharedTeam->id, 'user_id' => $user->id]);
    $this->assertModelMissing($sentInvitation);
    $this->assertModelMissing($receivedInvitation);
});

test('profile validation applies email uniqueness for new and existing users', function () {
    $user = User::factory()->create();
    $rules = new class
    {
        use ProfileValidationRules;

        /** @return array<int, ValidationRule|array<mixed>|string> */
        public function emailRulesFor(?int $userId): array
        {
            return $this->emailRules($userId);
        }
    };

    $newUserValidation = Validator::make(['email' => $user->email], ['email' => $rules->emailRulesFor(null)]);
    $existingUserValidation = Validator::make(['email' => $user->email], ['email' => $rules->emailRulesFor($user->id)]);

    expect($newUserValidation->fails())->toBeTrue()
        ->and($existingUserValidation->passes())->toBeTrue();
});
