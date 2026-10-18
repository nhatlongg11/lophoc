<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuestionBankTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        $token = 'bank-'.$user->id;
        DB::table('api_tokens')->insertOrIgnore(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_banks_persist_tags_and_answers_but_remain_private_to_the_author(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $other = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $subject = DB::table('subjects')->insertGetId(['name' => 'Toán']);
        $this->withHeaders($this->headers($teacher));
        $payload = ['name' => 'Phép cộng', 'subject_id' => $subject, 'grade' => '2', 'topic' => 'Số học', 'difficulty' => 'easy', 'questions' => [['type' => 'single', 'prompt' => '2+2?', 'options' => ['3', '4'], 'answer' => '4', 'points' => 1]]];
        $id = $this->postJson('/api/question-banks', $payload)->assertCreated()->json('id');
        $this->getJson('/api/question-banks/'.$id)->assertOk()->assertJsonPath('questions.0.answer', '4')->assertJsonPath('grade', '2');
        $this->withHeaders($this->headers($other))->getJson('/api/question-banks/'.$id)->assertNotFound();
        $this->getJson('/api/question-banks')->assertExactJson([]);
        $this->withHeaders($this->headers($student))->getJson('/api/question-banks/'.$id)->assertForbidden();
        $this->withHeaders($this->headers($teacher))->deleteJson('/api/question-banks/'.$id)->assertOk();
        $this->getJson('/api/question-banks/'.$id)->assertNotFound();
        $this->assertDatabaseCount('bank_questions', 1);
    }
}
