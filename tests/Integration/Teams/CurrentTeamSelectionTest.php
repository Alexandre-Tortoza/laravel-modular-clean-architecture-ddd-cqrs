<?php

use App\Modules\Teams\Infrastructure\Persistence\Eloquent\CurrentTeamSelection;
use Tests\Support\UserWithPersonalTeam;

test('selecting a team updates the selection and user timestamp without changing identity data', function (): void {
    $this->travelTo('2026-01-01 12:00:00');
    $user = UserWithPersonalTeam::create(['name' => 'Taylor', 'email' => 'taylor@example.com']);

    $this->travelTo('2026-01-02 12:00:00');
    app(CurrentTeamSelection::class)->select($user->id, null);
    $updated = $user->fresh();

    expect($updated->current_team_id)->toBeNull()
        ->and($updated->updated_at->format('Y-m-d H:i:s'))->toBe('2026-01-02 12:00:00')
        ->and($updated->name)->toBe('Taylor')
        ->and($updated->email)->toBe('taylor@example.com');
});
