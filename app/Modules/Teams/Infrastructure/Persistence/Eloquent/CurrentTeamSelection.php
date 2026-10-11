<?php

namespace App\Modules\Teams\Infrastructure\Persistence\Eloquent;

use Illuminate\Support\Facades\DB;

class CurrentTeamSelection
{
    public function forUser(int $userId): ?int
    {
        $teamId = DB::table('users')->where('id', $userId)->value('current_team_id');

        return $teamId === null ? null : (int) $teamId;
    }

    /** @return list<int> */
    public function userIdsForTeam(int $teamId): array
    {
        return array_values(DB::table('users')->where('current_team_id', $teamId)->pluck('id')->map(fn (int $id): int => $id)->all());
    }

    public function select(int $userId, ?int $teamId): void
    {
        DB::table('users')->where('id', $userId)->update(['current_team_id' => $teamId, 'updated_at' => now()]);
    }
}
