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
        Schema::create('teacher_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->unique(['teacher_id', 'student_id']);
            $table->index(['organization_id', 'student_id']);
        });
        DB::table('class_memberships')->join('school_classes', 'class_memberships.class_id', '=', 'school_classes.id')->whereColumn('class_memberships.organization_id', 'school_classes.organization_id')->select('class_memberships.organization_id', 'school_classes.teacher_id', 'class_memberships.student_id')->distinct()->orderBy('class_memberships.student_id')->chunk(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('teacher_students')->insertOrIgnore((array) $row);
            }
        });
        foreach (['assignment' => 'assignments', 'lesson' => 'lessons', 'game' => 'games'] as $kind => $parent) {
            Schema::create($kind.'_students', function (Blueprint $table) use ($kind, $parent) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->restrictOnDelete();
                $table->foreignId($kind.'_id')->constrained($parent)->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
                $table->unique([$kind.'_id', 'student_id']);
                $table->index(['organization_id', 'student_id']);
            });
        }
        Schema::create('material_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('attachment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->unique(['attachment_id', 'student_id']);
            $table->index(['organization_id', 'student_id']);
        });
        foreach (['assignments', 'games'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete());
        }
        foreach (['timetable_recurrences', 'announcements'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->json('student_ids')->nullable());
        }

        foreach (['assignments' => 'assignment', 'lessons' => 'lesson', 'games' => 'game'] as $parent => $kind) {
            DB::table($parent)->orderBy('id')->chunkById(100, function ($rows) use ($parent, $kind) {
                foreach ($rows as $row) {
                    $classes = $parent === 'assignments'
                        ? DB::table('assignment_classes')->where('assignment_id', $row->id)->pluck('class_id')->all()
                        : [$row->class_id];
                    $students = $this->members($row->organization_id, $classes);
                    $history = match ($kind) {
                        'assignment' => DB::table('submissions')->where('assignment_id', $row->id)->pluck('student_id')->all(),
                        'lesson' => DB::table('attendance_records')->where('lesson_id', $row->id)->pluck('student_id')->all(),
                        'game' => DB::table('game_attempts')->where('game_id', $row->id)->pluck('student_id')->all(),
                    };
                    $students = DB::table('users')->where('organization_id', $row->organization_id)->where('role', 'student')->whereIn('id', array_unique([...$students, ...$history]))->pluck('id')->all();
                    foreach ($students as $student) {
                        DB::table($kind.'_students')->insertOrIgnore(['organization_id' => $row->organization_id, $kind.'_id' => $row->id, 'student_id' => $student]);
                        DB::table('teacher_students')->insertOrIgnore(['organization_id' => $row->organization_id, 'teacher_id' => $row->teacher_id, 'student_id' => $student]);
                    }
                    if ($parent !== 'lessons') {
                        $subject = DB::table('school_classes')->where('organization_id', $row->organization_id)->whereIn('id', $classes)->orderBy('id')->value('subject_id');
                        DB::table($parent)->where('id', $row->id)->update(['subject_id' => $subject]);
                    }
                }
            });
        }
        foreach (['timetable_recurrences', 'announcements', 'arcade_assignments'] as $table) {
            DB::table($table)->orderBy('id')->chunkById(100, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    $students = $row->class_id ? $this->members($row->organization_id, [$row->class_id])
                        : DB::table('users')->where('organization_id', $row->organization_id)->where('role', 'student')->pluck('id')->all();
                    if ($table === 'arcade_assignments' && $row->student_ids !== null) {
                        $students = array_values(array_intersect($students, json_decode($row->student_ids, true) ?: []));
                    }
                    if ($table === 'arcade_assignments') {
                        $history = DB::table('arcade_sessions')->where('organization_id', $row->organization_id)->where('assignment_id', $row->id)->where('is_preview', false)->pluck('student_id')->all();
                        $students = array_unique([...$students, ...$history]);
                    }
                    $students = DB::table('users')->where('organization_id', $row->organization_id)->where('role', 'student')->whereIn('id', $students)->pluck('id')->all();
                    $teacher = $table === 'timetable_recurrences' ? $row->teacher_id : ($table === 'arcade_assignments' ? $row->assigned_by : $row->author_id);
                    foreach ($students as $student) {
                        DB::table('teacher_students')->insertOrIgnore(['organization_id' => $row->organization_id, 'teacher_id' => $teacher, 'student_id' => $student]);
                    }
                    DB::table($table)->where('id', $row->id)->update(['student_ids' => json_encode(array_values(array_map('intval', $students)))]);
                }
            });
        }
        DB::table('material_permissions')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                foreach ($this->members($row->organization_id, [$row->class_id]) as $student) {
                    DB::table('material_students')->insertOrIgnore(['organization_id' => $row->organization_id, 'attachment_id' => $row->attachment_id, 'student_id' => $student]);
                }
            }
        });

        Schema::table('arcade_assignments', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'class_id', 'revoked_at']);
            $table->dropIndex(['round_id', 'version_id', 'class_id']);
            $table->index(['organization_id', 'revoked_at']);
        });
        foreach (['lessons', 'games', 'support_notes', 'announcements', 'timetable_recurrences', 'arcade_assignments'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                if (in_array('class_id', $index['columns'], true)) {
                    Schema::table($table, fn (Blueprint $table) => $table->dropIndex($index['name']));
                }
            }
            Schema::table($table, fn (Blueprint $table) => $table->dropConstrainedForeignId('class_id'));
        }
        Schema::drop('material_permissions');
        Schema::drop('assignment_classes');
        Schema::drop('class_memberships');
        Schema::drop('school_classes');
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('max_classes'));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('This migration removes the class model. Restore a pre-migration database backup to revert it.');
    }

    private function members(int $organization, array $classes): array
    {
        return DB::table('class_memberships')->where('organization_id', $organization)->whereIn('class_id', $classes)->distinct()->pluck('student_id')->all();
    }
};
