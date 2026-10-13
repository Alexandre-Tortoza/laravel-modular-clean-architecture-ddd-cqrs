<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        throw new RuntimeException('migration-secret-canary');
    }

    public function down(): void
    {
        throw new LogicException('No automatic destructive rollback is permitted.');
    }
};
