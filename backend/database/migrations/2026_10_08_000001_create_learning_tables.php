<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('student')->index();
            $table->string('phone')->nullable();
            $table->boolean('active')->default(true);
        });

        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('school_years', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('active')->default(false);
            $table->timestamps();
        });
        Schema::create('semesters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_year_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
        });
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('color')->default('#378477');
            $table->timestamps();
        });
        Schema::create('school_classes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('subject_id')->constrained();
            $table->foreignId('teacher_id')->constrained('users');
            $table->foreignId('school_year_id')->constrained();
            $table->string('room')->nullable();
            $table->timestamps();
            $table->unique(['name', 'school_year_id']);
        });
        Schema::create('class_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['class_id', 'student_id']);
        });
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users');
            $table->foreignId('subject_id')->constrained();
            $table->string('title');
            $table->string('room')->nullable();
            $table->unsignedTinyInteger('period')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status')->default('scheduled');
            $table->text('note')->nullable();
            $table->timestamps();
        });
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users');
            $table->string('title');
            $table->text('instructions');
            $table->text('criteria')->nullable();
            $table->decimal('max_score', 6, 2)->default(10);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('due_at');
            $table->string('submission_type')->default('both');
            $table->boolean('published')->default(true);
            $table->timestamps();
        });
        Schema::create('assignment_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->unique(['assignment_id', 'class_id']);
        });
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('submission_id')->nullable();
            $table->foreignId('resource_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('path')->nullable();
            $table->string('url')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();
        });
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->longText('answer')->nullable();
            $table->string('status')->default('draft');
            $table->dateTime('submitted_at')->nullable();
            $table->decimal('score', 6, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('graded_at')->nullable();
            $table->timestamps();
            $table->unique(['assignment_id', 'student_id']);
        });
        Schema::table('attachments', fn (Blueprint $table) => $table->foreign('submission_id')->references('id')->on('submissions')->cascadeOnDelete());
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users');
            $table->foreignId('class_id')->constrained('school_classes');
            $table->string('title');
            $table->string('type')->default('quiz');
            $table->boolean('graded')->default(false);
            $table->unsignedInteger('time_limit_seconds')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->timestamps();
        });
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->json('options');
            $table->json('answer');
            $table->string('topic')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('game_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->json('answers');
            $table->decimal('score', 6, 2);
            $table->unsignedInteger('correct_count');
            $table->unsignedInteger('incorrect_count');
            $table->unsignedInteger('duration_seconds');
            $table->json('wrong_topics')->nullable();
            $table->timestamps();
        });
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('present');
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();
            $table->unique(['lesson_id', 'student_id']);
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('kind')->default('info');
            $table->string('url')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        Schema::create('support_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users');
            $table->foreignId('class_id')->constrained('school_classes');
            $table->string('level')->default('yellow');
            $table->text('note');
            $table->text('plan')->nullable();
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamps();
        });
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['app_settings', 'audit_logs', 'support_notes', 'notifications', 'attendance_records', 'game_attempts', 'questions', 'games', 'attachments', 'submissions', 'assignment_classes', 'assignments', 'lessons', 'class_memberships', 'school_classes', 'subjects', 'semesters', 'school_years', 'api_tokens'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'phone', 'active']));
    }
};
