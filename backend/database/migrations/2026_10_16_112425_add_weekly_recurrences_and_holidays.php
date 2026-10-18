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
        Schema::create('timetable_recurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_id')->constrained('school_classes')->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->string('title', 180);
            $table->string('room', 80)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->json('weekdays');
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'starts_on', 'ends_on']);
        });
        Schema::table('lessons', function (Blueprint $table) {
            $table->foreignId('recurrence_id')->nullable()->constrained('timetable_recurrences')->restrictOnDelete();
            $table->date('occurrence_on')->nullable();
            $table->boolean('is_exception')->default(false);
            $table->unique(['recurrence_id', 'occurrence_on']);
            $table->index(['organization_id', 'starts_at', 'ends_at']);
        });
        Schema::create('school_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('title', 180);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_holidays');
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropUnique(['recurrence_id', 'occurrence_on']);
            $table->dropForeign(['recurrence_id']);
            $table->dropColumn(['recurrence_id', 'occurrence_on', 'is_exception']);
        });
        Schema::dropIfExists('timetable_recurrences');
    }
};
