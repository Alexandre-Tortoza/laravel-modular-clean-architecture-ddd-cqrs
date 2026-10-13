<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::select('SELECT pg_sleep(2)');
        Schema::table('users', function (Blueprint $table): void {
            $table->text('release_compatibility_note')->nullable();
        });
    }

    public function down(): void
    {
        throw new LogicException('The release smoke never rolls schema back automatically.');
    }
};
