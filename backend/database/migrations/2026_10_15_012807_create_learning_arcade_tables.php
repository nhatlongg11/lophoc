<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arcade_rounds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('subject_id')->constrained()->restrictOnDelete();
            $t->string('title', 180);
            $t->string('grade', 40);
            $t->string('topic', 100);
            $t->string('status')->default('draft');
            $t->unsignedBigInteger('draft_version_id')->nullable();
            $t->unsignedBigInteger('published_version_id')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->boolean('is_demo')->default(false);
            $t->timestamps();
            $t->index(['organization_id', 'teacher_id', 'archived_at']);
            $t->index(['organization_id', 'subject_id', 'grade']);
        });
        Schema::create('arcade_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('round_id')->constrained('arcade_rounds')->restrictOnDelete();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->foreignId('subject_id')->constrained()->restrictOnDelete();
            $t->string('title', 180);
            $t->string('grade', 40);
            $t->string('topic', 100);
            $t->string('schema_version')->default('arcade-v1');
            $t->json('content');
            $t->timestamps();
            $t->unique(['round_id', 'revision']);
        });
        Schema::create('arcade_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('round_id')->constrained('arcade_rounds')->restrictOnDelete();
            $t->foreignId('version_id')->constrained('arcade_versions')->restrictOnDelete();
            $t->foreignId('class_id')->constrained('school_classes')->restrictOnDelete();
            $t->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $t->json('student_ids')->nullable();
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('due_at')->nullable();
            $t->unsignedInteger('allowed_attempts')->default(3);
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'class_id', 'revoked_at']);
            $t->index(['round_id', 'version_id', 'class_id']);
        });
        Schema::create('arcade_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('round_id')->constrained('arcade_rounds')->restrictOnDelete();
            $t->foreignId('version_id')->constrained('arcade_versions')->restrictOnDelete();
            $t->foreignId('assignment_id')->nullable()->constrained('arcade_assignments')->restrictOnDelete();
            $t->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $t->boolean('is_preview')->default(false);
            $t->string('status')->default('active');
            $t->unsignedInteger('revision')->default(0);
            $t->json('progress');
            $t->timestamp('started_at');
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'student_id', 'status']);
            $t->index(['assignment_id', 'student_id', 'is_preview']);
        });
        Schema::create('arcade_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('session_id')->constrained('arcade_sessions')->restrictOnDelete();
            $t->string('event_key', 80);
            $t->string('payload_hash', 64);
            $t->json('feedback')->nullable();
            $t->timestamps();
            $t->unique(['session_id', 'event_key']);
        });
        Schema::create('arcade_results', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('session_id')->constrained('arcade_sessions')->restrictOnDelete();
            $t->foreignId('round_id')->constrained('arcade_rounds')->restrictOnDelete();
            $t->foreignId('version_id')->constrained('arcade_versions')->restrictOnDelete();
            $t->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('subject_id')->constrained()->restrictOnDelete();
            $t->string('activity_key', 64);
            $t->string('engine', 40);
            $t->string('topic', 100);
            $t->string('objective', 255);
            $t->unsignedInteger('correct_count');
            $t->unsignedInteger('total_count');
            $t->unsignedInteger('action_count');
            $t->unsignedInteger('hint_count')->default(0);
            $t->unsignedInteger('duration_seconds');
            $t->decimal('accuracy', 5, 2);
            $t->unsignedTinyInteger('stars');
            $t->unsignedInteger('points');
            $t->unsignedInteger('xp_awarded')->default(0);
            $t->json('details');
            $t->timestamps();
            $t->unique(['session_id', 'activity_key']);
            $t->index(['organization_id', 'student_id', 'subject_id', 'created_at'], 'arcade_result_mastery');
            $t->index(['organization_id', 'round_id', 'created_at']);
        });
        Schema::create('arcade_rewards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('round_id')->constrained('arcade_rounds')->restrictOnDelete();
            $t->foreignId('session_id')->constrained('arcade_sessions')->restrictOnDelete();
            $t->string('reward_key', 100);
            $t->unsignedInteger('amount');
            $t->timestamps();
            $t->unique(['student_id', 'round_id', 'reward_key']);
        });
        Schema::create('arcade_mistakes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('round_id')->constrained('arcade_rounds')->restrictOnDelete();
            $t->foreignId('version_id')->constrained('arcade_versions')->restrictOnDelete();
            $t->string('activity_key', 64);
            $t->string('item_key', 64);
            $t->string('topic', 100);
            $t->string('objective', 255);
            $t->json('review');
            $t->unsignedInteger('wrong_count')->default(1);
            $t->timestamp('last_wrong_at');
            $t->timestamp('corrected_at')->nullable();
            $t->timestamps();
            $t->unique(['student_id', 'version_id', 'activity_key', 'item_key'], 'arcade_mistake_identity');
            $t->index(['organization_id', 'student_id', 'corrected_at']);
        });
        Schema::create('arcade_publish_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('round_id')->constrained('arcade_rounds')->restrictOnDelete();
            $t->foreignId('version_id')->nullable()->constrained('arcade_versions')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 40);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Learning Arcade history must be preserved. Use a forward fix or restore a verified backup.');
    }
};
