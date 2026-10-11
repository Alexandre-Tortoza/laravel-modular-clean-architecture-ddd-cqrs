<?php

use App\Models\User;
use App\Modules\Teams\Application\Commands\AcceptTeamInvitation;
use App\Modules\Teams\Application\Commands\CancelTeamInvitation;
use App\Modules\Teams\Application\Commands\CreateTeamInvitation;
use App\Modules\Teams\Application\Commands\DeclineTeamInvitation;
use App\Modules\Teams\Application\Commands\TeamInvitationHandler;
use App\Modules\Teams\Application\Contracts\SendsTeamInvitations;
use App\Modules\Teams\Application\Data\TeamInvitationData;
use App\Modules\Teams\Application\Exceptions\TeamInvitationCreationRejected;
use App\Modules\Teams\Application\Exceptions\TeamInvitationResponseRejected;
use App\Modules\Teams\Application\Exceptions\TeamOperationRejected;
use App\Modules\Teams\Domain\Enums\TeamRole;
use App\Modules\Teams\Infrastructure\Notifications\TeamInvitation as TeamInvitationNotification;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\Team;
use App\Modules\Teams\Infrastructure\Persistence\Eloquent\Models\TeamInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

test('handler rejects owner invitations', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    expect(fn () => app(TeamInvitationHandler::class)->create(new CreateTeamInvitation($team->id, $owner->id, 'invited@example.com', TeamRole::Owner)))
        ->toThrow(TeamOperationRejected::class);

    $this->assertDatabaseMissing('team_invitations', ['team_id' => $team->id, 'email' => 'invited@example.com']);
    Notification::assertNothingSent();
});

test('handler rejects an invitation when the recipient is already a member', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $recipient = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($recipient, ['role' => TeamRole::Member->value]);

    expect(fn () => app(TeamInvitationHandler::class)->create(new CreateTeamInvitation($team->id, $owner->id, $recipient->email, TeamRole::Member)))
        ->toThrow(TeamInvitationCreationRejected::class);

    $this->assertDatabaseMissing('team_invitations', ['team_id' => $team->id, 'email' => $recipient->email]);
    Notification::assertNothingSent();
});

test('handler rejects duplicate pending invitations without sending another notification', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    expect(fn () => app(TeamInvitationHandler::class)->create(new CreateTeamInvitation($team->id, $owner->id, $invitation->email, TeamRole::Member)))
        ->toThrow(TeamInvitationCreationRejected::class);

    expect(TeamInvitation::query()->where('team_id', $team->id)->count())->toBe(1);
    Notification::assertNothingSent();
});

test('handler checks the inviter role read under lock', function () {
    Notification::fake();

    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);

    expect(fn () => app(TeamInvitationHandler::class)->create(new CreateTeamInvitation($team->id, $member->id, 'invited@example.com', TeamRole::Member)))
        ->toThrow(TeamOperationRejected::class);

    $this->assertDatabaseMissing('team_invitations', ['team_id' => $team->id, 'email' => 'invited@example.com']);
    Notification::assertNothingSent();
});

test('handler checks the cancelling actor role read under lock', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $invitation = TeamInvitation::factory()->create(['team_id' => $team->id, 'invited_by' => $owner->id]);

    expect(fn () => app(TeamInvitationHandler::class)->cancel(new CancelTeamInvitation($team->id, $member->id, $invitation->id)))
        ->toThrow(TeamOperationRejected::class);

    $this->assertModelExists($invitation);
});

test('creation expires after three days and queues one notification', function () {
    $this->travelTo('2026-01-01 12:00:00');
    Notification::fake();

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $created = app(TeamInvitationHandler::class)->create(new CreateTeamInvitation($team->id, $owner->id, 'invited@example.com', TeamRole::Member));

    expect(TeamInvitation::query()->findOrFail($created->id)->expires_at->format('Y-m-d H:i:s'))->toBe('2026-01-04 12:00:00');
    Notification::assertSentOnDemandOnce(TeamInvitationNotification::class);
    Notification::assertSentOnDemand(TeamInvitationNotification::class, fn (TeamInvitationNotification $notification): bool => $notification->afterCommit === true);
});

test('handler sends once after its transaction completes', function () {
    $sender = new class implements SendsTeamInvitations
    {
        /** @var list<int> */
        public array $transactionLevels = [];

        public function send(TeamInvitationData $invitation): void
        {
            $this->transactionLevels[] = DB::transactionLevel();
        }
    };

    app()->instance(SendsTeamInvitations::class, $sender);
    $transactionLevel = DB::transactionLevel();

    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    app(TeamInvitationHandler::class)->create(new CreateTeamInvitation($team->id, $owner->id, 'invited@example.com', TeamRole::Member));

    expect($sender->transactionLevels)->toBe([$transactionLevel]);
});

test('accept and decline reject an invitation at its expiration time', function (string $response) {
    $this->travelTo('2026-01-01 12:00:00');

    $owner = User::factory()->create();
    $recipient = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $recipient->email,
        'invited_by' => $owner->id,
        'expires_at' => '2026-01-04 12:00:00',
    ]);

    $this->travelTo('2026-01-04 12:00:00');
    $command = $response === 'accept'
        ? new AcceptTeamInvitation($recipient->id, $invitation->id)
        : new DeclineTeamInvitation($recipient->id, $invitation->id);

    expect(fn () => app(TeamInvitationHandler::class)->{$response}($command))
        ->toThrow(TeamInvitationResponseRejected::class);

    $this->assertModelExists($invitation);
    expect($recipient->fresh()->belongsToTeam($team))->toBeFalse();
})->with(['accept', 'decline']);

test('handler returns the selected team slug on acceptance', function () {
    $owner = User::factory()->create();
    $recipient = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $recipient->email,
        'invited_by' => $owner->id,
    ]);

    $slug = app(TeamInvitationHandler::class)->accept(new AcceptTeamInvitation($recipient->id, $invitation->id));

    expect($slug)->toBe($team->slug)
        ->and($recipient->fresh()->current_team_id)->toBe($team->id);
});

test('invitation locks recipient users and the inviter in ascending ID order', function () {
    Notification::fake();

    $firstRecipient = User::factory()->create(['email' => 'Invited@example.com']);
    $inviter = User::factory()->create();
    $lastRecipient = User::factory()->create(['email' => 'invited@example.com']);
    $team = Team::factory()->create();
    $team->members()->attach($inviter, ['role' => TeamRole::Owner->value]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(TeamInvitationHandler::class)->create(new CreateTeamInvitation($team->id, $inviter->id, 'INVITED@example.com', TeamRole::Member));

    $lockedUserIds = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'from "users"') && str_contains($query['query'], 'where "users"."id" = ?'))
        ->map(fn (array $query): int => (int) $query['bindings'][0])
        ->values()
        ->all();

    expect($lockedUserIds)->toBe([$firstRecipient->id, $inviter->id, $lastRecipient->id]);
});
