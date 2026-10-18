<?php

namespace Tests;

use App\Models\User;
use Illuminate\Support\Facades\DB;

trait EduQuestFixtures
{
    protected function eduFixture(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(9, 0));
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $parent = User::factory()->create(['role' => 'parent']);
        $other = User::factory()->create(['role' => 'parent']);
        $subject = DB::table('subjects')->insertGetId(['name' => 'Math']);
        $year = DB::table('school_years')->insertGetId(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2027-01-01']);
        $class = DB::table('school_classes')->insertGetId(['name' => 'A', 'teacher_id' => $teacher->id, 'subject_id' => $subject, 'school_year_id' => $year]);
        DB::table('class_memberships')->insert(['class_id' => $class, 'student_id' => $student->id]);
        DB::table('parent_student_links')->insert(['organization_id' => 1, 'parent_id' => $parent->id, 'student_id' => $student->id]);

        return compact('teacher', 'student', 'parent', 'other', 'subject', 'class');
    }

    protected function eduAs(User $user): void
    {
        $token = 'edu-test-'.$user->id;
        DB::table('api_tokens')->insertOrIgnore(['user_id' => $user->id, 'organization_id' => $user->organization_id, 'token_hash' => hash('sha256', $token), 'mode' => $user->role === 'parent' ? 'parent' : 'account', 'parent_verified_until' => now()->addHour(), 'expires_at' => now()->addDay()]);
        $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }
}
