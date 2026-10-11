<?php

use App\Models\User;
use App\Modules\Teams\Domain\Enums\TeamPermission;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Presentation\Policies\TeamPolicy;

test('allows viewing only teams a user belongs to', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    $policy = app(TeamPolicy::class);

    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->create($user))->toBeTrue()
        ->and($policy->view($user, $team))->toBeTrue()
        ->and($policy->view(User::factory()->create(), $team))->toBeFalse();
});

test('allows actions only for roles with the required permission', function () {
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    $policy = app(TeamPolicy::class);

    foreach (TeamPermission::cases() as $permission) {
        expect($owner->hasTeamPermission($team, $permission))->toBeTrue();
    }

    expect($policy->update($admin, $team))->toBeTrue()
        ->and($policy->inviteMember($admin, $team))->toBeTrue()
        ->and($policy->cancelInvitation($admin, $team))->toBeTrue()
        ->and($policy->addMember($admin, $team))->toBeFalse()
        ->and($policy->updateMember($member, $team))->toBeFalse()
        ->and($policy->removeMember($member, $team))->toBeFalse()
        ->and($policy->delete($member, $team))->toBeFalse();
});

test('allows only non-owner members to leave non-personal teams', function () {
    $policy = app(TeamPolicy::class);
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    expect($policy->leave($member, $team))->toBeTrue()
        ->and($policy->leave($owner, $team))->toBeFalse()
        ->and($policy->leave($outsider, $team))->toBeFalse()
        ->and($policy->leave($member, Team::factory()->personal()->create()))->toBeFalse()
        ->and($policy->delete($owner, $team))->toBeTrue()
        ->and($policy->delete($owner, Team::factory()->personal()->create()))->toBeFalse();
});
