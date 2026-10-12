<?php

namespace App\Modules\Identity\Infrastructure\Persistence\Eloquent\Queries;

use App\Modules\Identity\Application\Api\UserDirectory;
use App\Modules\Identity\Application\Api\UserRecord;
use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use Illuminate\Database\Eloquent\Builder;

class EloquentUserDirectory implements UserDirectory
{
    public function find(int $id): ?UserRecord
    {
        $user = User::query()->find($id);

        return $user === null ? null : $this->record($user);
    }

    public function findByEmail(string $email): ?UserRecord
    {
        $user = User::query()->where('email', $email)->first();

        return $user === null ? null : $this->record($user);
    }

    public function findAllByEmail(string $email): array
    {
        $records = [];

        foreach (User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->orderBy('id')->get() as $user) {
            $records[$user->id] = $this->record($user);
        }

        return $records;
    }

    public function findMany(array $ids): array
    {
        return $this->recordsFor(User::query(), $ids);
    }

    public function currentTeamIdFor(int $userId): ?int
    {
        $teamId = User::query()->whereKey($userId)->value('current_team_id');

        return $teamId === null ? null : (int) $teamId;
    }

    /**
     * @param  Builder<User>  $query
     * @param  array<int, int>  $ids
     * @return array<int, UserRecord>
     */
    private function recordsFor(Builder $query, array $ids): array
    {
        $orderedIds = array_values(array_unique($ids));
        sort($orderedIds, SORT_NUMERIC);

        $records = [];

        foreach ($query->whereKey($orderedIds)->orderBy('id')->get() as $user) {
            $records[$user->id] = $this->record($user);
        }

        return $records;
    }

    private function record(User $user): UserRecord
    {
        return new UserRecord($user->id, $user->name, $user->email, $user->avatar);
    }
}
