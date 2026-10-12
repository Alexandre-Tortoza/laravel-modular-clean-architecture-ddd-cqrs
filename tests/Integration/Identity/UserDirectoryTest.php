<?php

use App\Modules\Identity\Application\Api\TeamSelectionAccounts;
use App\Modules\Identity\Application\Api\UserDirectory;
use App\Modules\Identity\Application\Api\UserRecord;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use Illuminate\Support\Facades\DB;

test('user directory returns user records without exposing Eloquent', function () {
    $user = User::factory()->create(['name' => 'Directory User', 'email' => 'directory@example.com']);

    $directory = app(UserDirectory::class);
    $byId = $directory->find($user->id);
    $byEmail = $directory->findByEmail('directory@example.com');

    expect($byId)->toBeInstanceOf(UserRecord::class)
        ->and($byId->id)->toBe($user->id)
        ->and($byId->name)->toBe('Directory User')
        ->and($byEmail)->toEqual($byId);
});

test('Teams write integration locks users in ascending id order inside the caller transaction', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $records = DB::transaction(fn () => app(TeamSelectionAccounts::class)->lockMany([$second->id, $first->id]));

    expect(array_keys($records))->toBe([$first->id, $second->id]);
});

test('user directory finds every case insensitive email match in id order', function () {
    $first = User::factory()->create(['email' => 'Member@example.com']);
    $second = User::factory()->create(['email' => 'MEMBER@example.com']);

    $matches = app(UserDirectory::class)->findAllByEmail('member@example.com');

    expect(array_keys($matches))->toBe([$first->id, $second->id]);
});

test('user directory returns null for unknown id and email', function () {
    $directory = app(UserDirectory::class);

    expect($directory->find(999))->toBeNull()
        ->and($directory->findByEmail('missing@example.com'))->toBeNull();
});

test('identity user factory creates no team', function () {
    $user = User::factory()->create();

    expect($user->fresh()->current_team_id)->toBeNull()
        ->and(DB::table('teams')->count())->toBe(0);
});
