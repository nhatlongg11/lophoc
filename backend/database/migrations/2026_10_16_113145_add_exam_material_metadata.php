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
        Schema::table('attachments', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->foreignId('subject_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('grade', 40)->nullable();
            $table->string('semester', 30)->nullable();
            $table->string('exam_category', 40)->default('general');
            $table->string('visibility', 20)->default('assigned');
            $table->boolean('download_allowed')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('current_file_id')->nullable()->constrained('attachments')->restrictOnDelete();
            $table->foreignId('practice_round_id')->nullable()->constrained('arcade_rounds')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropForeign(['subject_id']);
            $table->dropForeign(['current_file_id']);
            $table->dropForeign(['practice_round_id']);
            $table->dropColumn(['description', 'subject_id', 'grade', 'semester', 'exam_category', 'visibility', 'download_allowed', 'published_at', 'current_file_id', 'practice_round_id']);
        });
    }
};
