<?php

namespace App\Modules\Teams\Domain\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @return array<TeamPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => TeamPermission::cases(),
            self::Admin => [
                TeamPermission::UpdateTeam,
                TeamPermission::CreateInvitation,
                TeamPermission::CancelInvitation,
            ],
            self::Member => [],
        };
    }

    public function hasPermission(TeamPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function level(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Admin => 2,
            self::Member => 1,
        };
    }

    public function isAtLeast(self $role): bool
    {
        return $this->level() >= $role->level();
    }

    /**
     * @return array<array{value: string, label: string}>
     */
    public static function assignable(): array
    {
        return [
            ['value' => self::Admin->value, 'label' => self::Admin->label()],
            ['value' => self::Member->value, 'label' => self::Member->label()],
        ];
    }
}
