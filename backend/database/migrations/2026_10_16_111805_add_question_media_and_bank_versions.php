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
        Schema::create('question_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('attachment_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamps();
        });
        Schema::create('question_media_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('media_id')->constrained('question_media')->restrictOnDelete();
            $table->string('content_type', 30);
            $table->unsignedBigInteger('content_id');
            $table->unique(['media_id', 'content_type', 'content_id'], 'media_content_unique');
            $table->index(['organization_id', 'content_type', 'content_id']);
        });
        Schema::table('assignment_questions', function (Blueprint $table) {
            $table->json('media')->nullable();
            $table->json('choice_media')->nullable();
            $table->text('explanation')->nullable();
        });
        Schema::table('question_banks', fn (Blueprint $table) => $table->unsignedInteger('revision')->default(0));
        Schema::create('question_bank_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('question_bank_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->json('content');
            $table->timestamps();
            $table->unique(['question_bank_id', 'revision']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_bank_versions');
        Schema::table('question_banks', fn (Blueprint $table) => $table->dropColumn('revision'));
        Schema::table('assignment_questions', fn (Blueprint $table) => $table->dropColumn(['media', 'choice_media', 'explanation']));
        Schema::dropIfExists('question_media_links');
        Schema::dropIfExists('question_media');
    }
};
