<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DirectRecipientMigrationTest extends TestCase
{
    public function test_upgrade_preserves_learning_history_and_expands_only_the_original_recipients(): void
    {
        $original = DB::getDefaultConnection();
        $config = config('database.connections.'.$original);
        $schema = 'recipient_backfill_'.bin2hex(random_bytes(6));
        if ($config['driver'] === 'pgsql') {
            DB::statement('CREATE SCHEMA '.$schema);
            $config['search_path'] = $schema;
        } else {
            $config = ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true];
        }
        config(['database.connections.recipient_backfill' => $config]);
        DB::setDefaultConnection('recipient_backfill');
        try {
            $upgrade = database_path('migrations/2026_10_18_213451_replace_classes_with_direct_student_recipients.php');
            $migrations = array_filter(glob(database_path('migrations/*.php')), fn ($path) => strcmp($path, $upgrade) < 0);
            sort($migrations);
            foreach ($migrations as $path) {
                (require $path)->up();
            }
            $teacher = User::factory()->create(['role' => 'teacher']);
            $student = User::factory()->create(['role' => 'student']);
            $formerStudent = User::factory()->create(['role' => 'student']);
            $unselected = User::factory()->create(['role' => 'student']);
            $otherOrg = DB::table('organizations')->insertGetId(['name' => 'Other', 'slug' => 'other']);
            $outsider = User::factory()->create(['role' => 'student', 'organization_id' => $otherOrg]);
            $subject = DB::table('subjects')->insertGetId(['name' => 'Math']);
            $year = DB::table('school_years')->insertGetId(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
            $class = DB::table('school_classes')->insertGetId(['teacher_id' => $teacher->id, 'subject_id' => $subject, 'school_year_id' => $year, 'name' => 'Legacy']);
            foreach ([$student, $unselected] as $member) {
                DB::table('class_memberships')->insert(['class_id' => $class, 'student_id' => $member->id]);
            }
            $assignment = DB::table('assignments')->insertGetId(['teacher_id' => $teacher->id, 'title' => 'Homework', 'instructions' => 'Answer', 'due_at' => now()->addDay()]);
            DB::table('assignment_classes')->insert(['assignment_id' => $assignment, 'class_id' => $class]);
            $submission = DB::table('submissions')->insertGetId(['assignment_id' => $assignment, 'student_id' => $formerStudent->id, 'answer' => 'Historical answer', 'score' => 8, 'status' => 'graded', 'submitted_at' => now()]);
            $lesson = DB::table('lessons')->insertGetId(['class_id' => $class, 'subject_id' => $subject, 'teacher_id' => $teacher->id, 'title' => 'Lesson', 'starts_at' => now(), 'ends_at' => now()->addHour()]);
            $attendance = DB::table('attendance_records')->insertGetId(['lesson_id' => $lesson, 'student_id' => $formerStudent->id, 'recorded_by' => $teacher->id, 'status' => 'late', 'note' => 'Retained']);
            $game = DB::table('games')->insertGetId(['teacher_id' => $teacher->id, 'class_id' => $class, 'title' => 'Game']);
            $attempt = DB::table('game_attempts')->insertGetId(['game_id' => $game, 'student_id' => $formerStudent->id, 'answers' => '{}', 'score' => 7, 'correct_count' => 7, 'incorrect_count' => 3, 'duration_seconds' => 45]);
            $file = DB::table('attachments')->insertGetId(['resource_owner_id' => $teacher->id, 'name' => 'Lesson.pdf', 'path' => 'private/lesson.pdf']);
            DB::table('material_permissions')->insert(['organization_id' => 1, 'attachment_id' => $file, 'class_id' => $class]);
            $note = DB::table('support_notes')->insertGetId(['class_id' => $class, 'student_id' => $student->id, 'teacher_id' => $teacher->id, 'note' => 'Keep support plan']);
            $recurrence = DB::table('timetable_recurrences')->insertGetId(['organization_id' => 1, 'class_id' => $class, 'teacher_id' => $teacher->id, 'subject_id' => $subject, 'title' => 'Weekly', 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31', 'weekdays' => '[1]', 'start_time' => '09:00', 'end_time' => '10:00']);
            $announcement = DB::table('announcements')->insertGetId(['organization_id' => 1, 'class_id' => $class, 'author_id' => $teacher->id, 'title' => 'Notice', 'body' => 'Hello']);
            $round = DB::table('arcade_rounds')->insertGetId(['organization_id' => 1, 'teacher_id' => $teacher->id, 'subject_id' => $subject, 'title' => 'Arcade', 'grade' => '5', 'topic' => 'Math']);
            $version = DB::table('arcade_versions')->insertGetId(['organization_id' => 1, 'round_id' => $round, 'author_id' => $teacher->id, 'subject_id' => $subject, 'title' => 'Arcade', 'grade' => '5', 'topic' => 'Math', 'revision' => 1, 'content' => '{}']);
            $arcade = DB::table('arcade_assignments')->insertGetId(['organization_id' => 1, 'round_id' => $round, 'version_id' => $version, 'assigned_by' => $teacher->id, 'class_id' => $class, 'student_ids' => json_encode([$student->id, $outsider->id])]);
            $session = DB::table('arcade_sessions')->insertGetId(['organization_id' => 1, 'round_id' => $round, 'version_id' => $version, 'assignment_id' => $arcade, 'student_id' => $formerStudent->id, 'progress' => '{}', 'started_at' => now()]);

            (require $upgrade)->up();

            foreach (['school_classes', 'class_memberships', 'assignment_classes', 'material_permissions'] as $table) {
                $this->assertFalse(Schema::hasTable($table));
            }
            foreach (['lessons', 'games', 'support_notes', 'announcements', 'timetable_recurrences', 'arcade_assignments'] as $table) {
                $this->assertFalse(Schema::hasColumn($table, 'class_id'));
            }
            $this->assertFalse(Schema::hasColumn('organizations', 'max_classes'));
            foreach (['assignment' => $assignment, 'lesson' => $lesson, 'game' => $game] as $kind => $id) {
                $this->assertEqualsCanonicalizing([$student->id, $formerStudent->id, $unselected->id], DB::table($kind.'_students')->where($kind.'_id', $id)->pluck('student_id')->all());
            }
            $this->assertDatabaseHas('assignments', ['id' => $assignment, 'subject_id' => $subject]);
            $this->assertDatabaseHas('games', ['id' => $game, 'subject_id' => $subject]);
            $this->assertDatabaseHas('submissions', ['id' => $submission, 'answer' => 'Historical answer', 'score' => 8, 'status' => 'graded']);
            $this->assertDatabaseHas('attendance_records', ['id' => $attendance, 'note' => 'Retained', 'status' => 'late']);
            $this->assertDatabaseHas('game_attempts', ['id' => $attempt, 'score' => 7]);
            $this->assertDatabaseHas('attachments', ['id' => $file, 'path' => 'private/lesson.pdf']);
            $this->assertEqualsCanonicalizing([$student->id, $unselected->id], DB::table('material_students')->where('attachment_id', $file)->pluck('student_id')->all());
            $this->assertDatabaseHas('support_notes', ['id' => $note, 'note' => 'Keep support plan']);
            foreach (['timetable_recurrences' => $recurrence, 'announcements' => $announcement] as $table => $id) {
                $this->assertEqualsCanonicalizing([$student->id, $unselected->id], json_decode(DB::table($table)->where('id', $id)->value('student_ids'), true));
            }
            $this->assertEqualsCanonicalizing([$student->id, $formerStudent->id], json_decode(DB::table('arcade_assignments')->where('id', $arcade)->value('student_ids'), true));
            $this->assertDatabaseHas('arcade_sessions', ['id' => $session, 'assignment_id' => $arcade]);
            $this->assertDatabaseHas('teacher_students', ['teacher_id' => $teacher->id, 'student_id' => $formerStudent->id]);
            $this->assertDatabaseMissing('teacher_students', ['student_id' => $outsider->id]);
        } finally {
            DB::purge('recipient_backfill');
            DB::setDefaultConnection($original);
            if ($config['driver'] === 'pgsql') {
                DB::statement('DROP SCHEMA '.$schema.' CASCADE');
            }
        }
    }
}
