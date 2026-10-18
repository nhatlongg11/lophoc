<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('submission_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('submission_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->timestamps();
            $table->unique(['submission_id', 'version']);
        });
        DB::table('submissions')->whereNotNull('submitted_at')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::table('submission_versions')->insert(['organization_id' => $row->organization_id, 'submission_id' => $row->id, 'version' => 1, 'snapshot' => json_encode($row, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Restore the pre-migration backup to reverse immutable submission history.');
    }
};
