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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('plan')->default('free');
            $table->string('subscription_status')->default('active');
            $table->unsignedInteger('max_classes')->default(3);
            $table->unsignedInteger('max_students')->default(50);
            $table->unsignedInteger('max_teachers')->default(3);
            $table->unsignedBigInteger('storage_limit_bytes')->default(104857600);
            $table->boolean('leaderboard_enabled')->default(false);
            $table->timestamp('renews_at')->nullable();
            $table->timestamps();
        });
        DB::table('organizations')->insert(['name' => 'Trung tâm EduQuest', 'slug' => 'eduquest', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($this->tenantTables() as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('organization_id')->default(1)->constrained('organizations')->restrictOnDelete());
            Schema::table($name, fn (Blueprint $table) => $table->index('organization_id'));
        }
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['organization_id', 'name']);
        });
        Schema::table('school_years', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['organization_id', 'name']);
        });
        Schema::table('school_classes', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
            $table->dropUnique(['name', 'school_year_id']);
            $table->unique(['organization_id', 'name', 'school_year_id']);
        });
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropPrimary(['key']);
            $table->primary(['organization_id', 'key']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->string('parent_pin_hash')->nullable());
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->string('mode')->default('account');
            $table->foreignId('profile_student_id')->nullable()->constrained('users');
            $table->timestamp('parent_verified_until')->nullable();
        });
        Schema::create('parent_student_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['parent_id', 'student_id']);
        });
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('users');
            $table->string('email');
            $table->string('role');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
        Schema::table('assignments', function (Blueprint $table) {
            $table->unsignedInteger('allowed_attempts')->default(1);
            $table->string('grade_policy')->default('highest');
            $table->unsignedInteger('time_limit_seconds')->nullable();
            $table->boolean('release_answers')->default(false);
            $table->string('lifecycle')->default('published');
        });
        Schema::create('assignment_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('assignment_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->text('prompt');
            $table->json('options');
            $table->json('answer');
            $table->decimal('points', 7, 2)->default(1);
            $table->string('media_url', 2048)->nullable();
            $table->string('topic')->nullable();
            $table->unsignedInteger('sort_order');
            $table->timestamps();
        });
        Schema::create('assignment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('assignment_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('status')->default('draft');
            $table->json('answers');
            $table->json('question_snapshot');
            $table->json('grading')->nullable();
            $table->decimal('score', 7, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['assignment_id', 'student_id', 'attempt_number']);
            $table->index(['organization_id', 'student_id', 'status']);
        });
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedInteger('allowed_attempts')->default(10);
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('required_xp')->default(0);
            $table->timestamp('archived_at')->nullable();
        });
        Schema::table('questions', function (Blueprint $table) {
            $table->string('kind')->default('single');
            $table->string('media_url', 2048)->nullable();
        });
        Schema::table('game_sessions', fn (Blueprint $table) => $table->json('question_snapshot')->nullable());
        Schema::table('game_attempts', function (Blueprint $table) {
            $table->unsignedInteger('points')->default(0);
            $table->string('scoring_version')->default('eduquest-v1');
        });
        Schema::create('xp_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('game_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('amount');
            $table->timestamps();
            $table->unique(['student_id', 'game_id']);
        });
        Schema::create('student_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->string('code');
            $table->timestamps();
            $table->unique(['student_id', 'code']);
        });
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('school_classes');
            $table->foreignId('author_id')->constrained('users');
            $table->string('title');
            $table->text('body');
            $table->timestamps();
        });
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('sender_id')->constrained('users');
            $table->foreignId('recipient_id')->constrained('users');
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'recipient_id', 'read_at']);
        });
        Schema::create('billing_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->string('plan');
            $table->string('status')->default('pending');
            $table->timestamps();
        });
        Schema::table('attachments', function (Blueprint $table) {
            $table->foreignId('attempt_id')->nullable()->constrained('assignment_attempts')->restrictOnDelete();
            $table->string('folder')->default('');
            $table->timestamp('archived_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Forward-only: rolling back would discard educational history and tenant ownership.
        throw new RuntimeException('Restore the pre-migration backup to reverse this data-preserving migration.');
    }

    private function tenantTables(): array
    {
        return ['users', 'api_tokens', 'school_years', 'semesters', 'subjects', 'school_classes', 'class_memberships', 'lessons', 'assignments', 'assignment_classes', 'attachments', 'submissions', 'games', 'questions', 'game_attempts', 'attendance_records', 'notifications', 'support_notes', 'audit_logs', 'app_settings', 'game_sessions'];
    }
};
