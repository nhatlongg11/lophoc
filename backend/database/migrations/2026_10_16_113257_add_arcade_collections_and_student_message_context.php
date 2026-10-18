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
        Schema::table('arcade_versions', fn (Blueprint $table) => $table->unsignedTinyInteger('difficulty')->default(1));
        DB::table('arcade_versions')->orderBy('id')->chunkById(200, function ($versions) {
            foreach ($versions as $version) {
                $content = json_decode($version->content, true);
                DB::table('arcade_versions')->where('id', $version->id)->update(['difficulty' => max([1, ...array_column($content['activities'] ?? [], 'difficulty')])]);
            }
        });
        Schema::create('arcade_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::create('arcade_collection_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('collection_id')->constrained('arcade_collections')->restrictOnDelete();
            $table->foreignId('round_id')->constrained('arcade_rounds')->restrictOnDelete();
            $table->unsignedInteger('sort_order');
            $table->unique(['collection_id', 'round_id']);
        });
        Schema::table('arcade_assignments', fn (Blueprint $table) => $table->foreignId('collection_id')->nullable()->constrained('arcade_collections')->restrictOnDelete());
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('student_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['organization_id', 'student_id', 'sender_id', 'recipient_id'], 'student_message_context');
        });
        Schema::create('message_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('attachment_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploader_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('peer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->restrictOnDelete();
            $table->timestamps();
            $table->unique('attachment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('arcade_versions', fn (Blueprint $table) => $table->dropColumn('difficulty'));
        Schema::dropIfExists('message_files');
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('student_message_context');
            $table->dropForeign(['student_id']);
            $table->dropColumn('student_id');
        });
        Schema::table('arcade_assignments', function (Blueprint $table) {
            $table->dropForeign(['collection_id']);
            $table->dropColumn('collection_id');
        });
        Schema::dropIfExists('arcade_collection_rounds');
        Schema::dropIfExists('arcade_collections');
    }
};
