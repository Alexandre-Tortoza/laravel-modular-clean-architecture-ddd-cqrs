<?php

namespace App\Modules\Identity\Infrastructure\Persistence\Eloquent;

use App\Modules\Identity\Application\Api\TeamSelectionAccounts;
use App\Modules\Identity\Application\Api\UserRecord;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class EloquentTeamSelectionAccounts implements TeamSelectionAccounts
{
    public function lockMany(array $ids): array
    {
        $this->requireTransaction();
        $orderedIds = array_values(array_unique($ids));
        sort($orderedIds, SORT_NUMERIC);
        $records = [];

        foreach ($orderedIds as $id) {
            $user = User::query()->lockForUpdate()->findOrFail($id);
            $records[$id] = new UserRecord($user->id, $user->name, $user->email, $user->avatar, $user->hasVerifiedEmail());
        }

        return $records;
    }

    public function userIdsSelectingTeam(int $teamId): array
    {
        $this->requireTransaction();

        return array_values(User::query()->where('current_team_id', $teamId)
            ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    public function select(int $userId, ?int $teamId): void
    {
        $this->requireTransaction();
        User::query()->whereKey($userId)->update(['current_team_id' => $teamId]);
    }

    private function requireTransaction(): void
    {
        if (DB::connection((new User)->getConnectionName())->transactionLevel() === 0) {
            throw new LogicException('Team selection requires an active transaction on the User connection.');
        }
    }
}
