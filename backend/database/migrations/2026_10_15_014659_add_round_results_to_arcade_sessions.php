<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arcade_sessions', function (Blueprint $t) {
            $t->decimal('final_accuracy', 5, 2)->nullable();
            $t->unsignedTinyInteger('final_stars')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve stored round results. Use a forward fix or restore a verified backup.');
    }
};
