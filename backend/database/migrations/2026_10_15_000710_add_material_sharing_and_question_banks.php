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
        Schema::table('attachments', fn (Blueprint $table) => $table->string('disk')->default('local'));
        Schema::create('material_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('attachment_id')->constrained('attachments')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->unique(['attachment_id', 'class_id']);
        });
        Schema::create('question_banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('grade')->nullable();
            $table->string('topic')->nullable();
            $table->string('difficulty')->default('medium');
            $table->string('learning_objective')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'owner_id']);
        });
        Schema::create('bank_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('question_bank_id')->constrained()->cascadeOnDelete();
            $table->json('content');
            $table->unsignedInteger('sort_order');
            $table->timestamps();
        });
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('emailed_at')->nullable();
            $table->string('delivery_key')->nullable();
            $table->unique(['organization_id', 'user_id', 'delivery_key']);
        });
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('email_enabled')->default(false);
            $table->timestamps();
            $table->unique('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'user_id', 'delivery_key']);
            $table->dropColumn(['emailed_at', 'delivery_key']);
        });
        Schema::dropIfExists('bank_questions');
        Schema::dropIfExists('question_banks');
        Schema::dropIfExists('material_permissions');
        Schema::table('attachments', fn (Blueprint $table) => $table->dropColumn('disk'));
    }
};
