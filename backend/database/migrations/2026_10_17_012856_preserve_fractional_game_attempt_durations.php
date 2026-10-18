<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('game_attempts', function (Blueprint $table) {
            $table->decimal('duration_seconds', 16, 6)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('An integer rollback would discard historical fractional durations. Use a forward fix or restore the pre-migration backup.');
    }
};
