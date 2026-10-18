<?php

namespace Tests\Feature;

use App\Models\User;
use App\QuestionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GameEngineTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        $token = 'game-'.$user->id;
        DB::table('api_tokens')->insertOrIgnore(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);

        return ['Authorization' => 'Bearer '.$token];
    }

    private function game(User $teacher, User $student, string $type, array $question): int
    {
        $year = DB::table('school_years')->insertGetId(['name' => 'Year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $subject = DB::table('subjects')->insertGetId(['name' => 'English']);
        $class = DB::table('school_classes')->insertGetId(['name' => 'A', 'teacher_id' => $teacher->id, 'subject_id' => $subject, 'school_year_id' => $year]);
        DB::table('class_memberships')->insert(['class_id' => $class, 'student_id' => $student->id]);
        $this->withHeaders($this->headers($teacher));

        return $this->postJson('/api/games', ['title' => 'Practice', 'class_id' => $class, 'type' => $type, 'time_limit_seconds' => 60, 'allowed_attempts' => 2, 'questions' => [$question]])->assertCreated()->json('id');
    }

    #[DataProvider('modes')]
    public function test_six_modes_persist_server_scores_and_only_reward_xp_once(string $type, array $question, mixed $answer): void
    {
        $this->travelTo(now()->startOfSecond());
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $id = $this->game($teacher, $student, $type, $question);
        $this->withHeaders($this->headers($student));
        $session = $this->postJson('/api/games/'.$id.'/start')->assertOk()->json();
        $this->assertArrayNotHasKey('answer', $session['questions'][0]);
        $this->postJson('/api/games/'.$id.'/start')->assertOk()->assertJsonPath('session_id', $session['session_id']);
        $q = $session['questions'][0]['id'];
        $result = $this->postJson('/api/games/'.$id.'/attempt', ['session_id' => $session['session_id'], 'answers' => [$q => $answer], 'score' => 999999])->assertOk()->assertJsonPath('score', 10)->assertJsonPath('points', 370)->assertJsonPath('xp_awarded', 50);
        $this->postJson('/api/games/'.$id.'/attempt', ['session_id' => $session['session_id'], 'answers' => [$q => $answer]])->assertUnprocessable();
        $next = $this->postJson('/api/games/'.$id.'/start')->assertOk()->json();
        $this->postJson('/api/games/'.$id.'/attempt', ['session_id' => $next['session_id'], 'answers' => [$q => $answer]])->assertOk()->assertJsonPath('xp_awarded', 0);
        $this->postJson('/api/games/'.$id.'/start')->assertUnprocessable();
        $this->assertDatabaseCount('xp_transactions', 1);
        $this->assertDatabaseCount('student_achievements', 1);
        $this->getJson('/api/games-profile')->assertOk()->assertJsonPath('xp', 50)->assertJsonPath('streak', 1);
    }

    public static function modes(): array
    {
        return [
            'speed' => ['quiz', ['prompt' => '2+2?', 'options' => ['3', '4'], 'answer' => '4', 'kind' => 'single'], '4'],
            'pairs' => ['matching', ['prompt' => 'Match', 'options' => ['left' => ['cat', 'dog'], 'right' => ['chó', 'mèo']], 'answer' => ['cat' => 'mèo', 'dog' => 'chó'], 'kind' => 'matching'], ['dog' => 'chó', 'cat' => 'mèo']],
            'letters' => ['word_builder', ['prompt' => 'Build', 'options' => ['t', 'c', 'a'], 'answer' => 'cat', 'kind' => 'word_builder'], 'CAT'],
            'gap' => ['fill_blank', ['prompt' => 'I ___ a student', 'options' => [], 'answer' => ['am'], 'kind' => 'fill_blank'], ' Am '],
            'listening' => ['listening', ['prompt' => 'Listen', 'options' => ['cat', 'dog'], 'answer' => 'cat', 'kind' => 'audio', 'media_url' => 'https://example.test/teacher-audio.mp3'], 'cat'],
            'challenge' => ['challenge', ['prompt' => 'Order', 'options' => ['B', 'A'], 'answer' => ['A', 'B'], 'kind' => 'ordering'], ['A', 'B']],
        ];
    }

    public function test_historical_fractional_game_durations_remain_exact_in_student_history(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $game = $this->game($teacher, $student, 'quiz', ['prompt' => '2+2?', 'options' => ['3', '4'], 'answer' => '4', 'kind' => 'single']);
        DB::table('game_attempts')->insert(['game_id' => $game, 'student_id' => $student->id, 'answers' => '{}', 'score' => 10, 'correct_count' => 1, 'incorrect_count' => 0, 'duration_seconds' => 5.74609, 'created_at' => now(), 'updated_at' => now()]);
        $this->withHeaders($this->headers($student));
        $this->getJson('/api/games-profile')->assertOk()->assertJsonPath('history.0.duration_seconds', fn ($value) => (float) $value === 5.74609);
        $this->assertSame(5.74609, (float) DB::table('game_attempts')->value('duration_seconds'));
    }

    public function test_wrong_answers_receive_no_points_and_bonuses_are_bounded(): void
    {
        $engine = new QuestionEngine;
        $this->assertSame(0, $engine->gamePoints(0, 10, 999999, 999999));
        $this->assertSame(1400, $engine->gamePoints(10, 10, 999999, 999999));
        $this->assertTrue($engine->evaluate(['type' => 'multiple', 'answer' => ['a', 'b']], ['b', 'a']));
        $this->assertFalse($engine->evaluate(['type' => 'multiple', 'answer' => ['a', 'b']], ['a']));
        $this->assertFalse($engine->evaluate(['type' => 'ordering', 'answer' => ['a', 'b']], ['b', 'a']));
    }
}
