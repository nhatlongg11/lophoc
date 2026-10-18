<?php

namespace Tests\Feature;

use App\ArcadeTemplates;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LearningArcadeTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        $token = 'arcade-test-'.$user->id;
        DB::table('api_tokens')->insertOrIgnore(['organization_id' => $user->organization_id, 'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        return ['Authorization' => 'Bearer '.$token];
    }

    private function fixture(?string $engine = null, bool $linear = true): array
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $year = DB::table('school_years')->insertGetId(['name' => '2026–2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $subject = DB::table('subjects')->insertGetId(['name' => 'Tiếng Anh', 'color' => '#d9eaff', 'created_at' => now(), 'updated_at' => now()]);
        $class = DB::table('school_classes')->insertGetId(['name' => '5A', 'subject_id' => $subject, 'teacher_id' => $teacher->id, 'school_year_id' => $year, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('class_memberships')->insert(['class_id' => $class, 'student_id' => $student->id, 'created_at' => now(), 'updated_at' => now()]);
        $content = ArcadeTemplates::all()[6]['content'];
        $content['subject_id'] = $subject;
        $content['linear'] = $linear;
        if ($engine) {
            $content['activities'] = array_values(array_filter($content['activities'], fn ($a) => $a['engine'] === $engine));
        }
        $this->withHeaders($this->headers($teacher));
        $round = $this->postJson('/api/arcade/rounds', ['content' => $content, 'expected_revision' => 0])->assertCreated()->json('id');
        $this->postJson('/api/arcade/rounds/'.$round.'/preview')->assertOk()->assertJsonPath('is_preview', true);
        $this->postJson('/api/arcade/rounds/'.$round.'/publish')->assertOk();
        $assignment = $this->postJson('/api/arcade/rounds/'.$round.'/assign', ['class_id' => $class, 'allowed_attempts' => 3])->assertCreated()->json('id');

        return [$teacher, $student, $class, $round, $assignment, $content];
    }

    private function act(array $session, array $action, ?string $key = null): array
    {
        return $this->postJson('/api/arcade/sessions/'.$session['id'].'/actions', ['event_key' => $key ?? 'event-'.bin2hex(random_bytes(8)), 'revision' => $session['revision'], 'action' => $action])->assertOk()->json();
    }

    private function complete(array $session, array $activity): array
    {
        $id = $activity['id'];
        $session = $this->act($session, ['kind' => 'enter', 'activity_id' => $id]);
        if ($activity['engine'] === 'matching') {
            foreach ($activity['pairs'] as $pair) {
                $cards = collect($session['current_activity']['cards']);
                $left = $cards->first(fn ($c) => $c['side'] === 'left' && $c['text'] === $pair['left']);
                $right = $cards->first(fn ($c) => $c['side'] === 'right' && $c['text'] === $pair['right']);
                $session = $this->act($session, ['kind' => 'match', 'activity_id' => $id, 'left_id' => $left['id'], 'right_id' => $right['id']]);
            }
        } elseif ($activity['engine'] === 'memory_flip') {
            $known = [];
            $related = [];
            foreach ($activity['pairs'] as $p) {
                $related[$p['left']] = $p['right'];
                $related[$p['right']] = $p['left'];
            }
            for ($turn = 0; $turn < 80 && $session['current_activity']['state']['status'] !== 'completed'; $turn++) {
                $cards = collect($session['current_activity']['cards'])->where('matched', false)->values();
                $first = $cards->first(fn ($c) => ! isset($known[$c['id']])) ?? $cards->first();
                foreach ($cards as $card) {
                    if (isset($known[$card['id']]) && $cards->contains(fn ($c) => $c['id'] !== $card['id'] && isset($known[$c['id']]) && $known[$c['id']] === $related[$known[$card['id']]])) {
                        $first = $card;
                        break;
                    }
                }
                $session = $this->act($session, ['kind' => 'flip', 'activity_id' => $id, 'card_id' => $first['id']]);
                $revealed = collect($session['current_activity']['cards'])->firstWhere('id', $first['id']);
                $known[$first['id']] = $revealed['text'];
                $cards = collect($session['current_activity']['cards'])->where('matched', false)->where('id', '!=', $first['id']);
                $second = $cards->first(fn ($c) => isset($known[$c['id']]) && $known[$c['id']] === $related[$revealed['text']]) ?? $cards->first(fn ($c) => ! isset($known[$c['id']])) ?? $cards->first();
                $session = $this->act($session, ['kind' => 'flip', 'activity_id' => $id, 'card_id' => $second['id']]);
                $known[$second['id']] = collect($session['current_activity']['cards'])->firstWhere('id', $second['id'])['text'];
                if ($session['current_activity']['state']['resolving']) {
                    $session = $this->act($session, ['kind' => 'resolve', 'activity_id' => $id]);
                }
            }
            $this->assertSame('completed', $session['current_activity']['state']['status']);
        } else {
            foreach ($activity['items'] as $q) {
                $answer = $activity['engine'] === 'word_builder' ? $q['accepted_orders'][0] : $q['accepted_answers'][0];
                $session = $this->act($session, ['kind' => 'answer', 'activity_id' => $id, 'item_id' => $q['id'], 'answer' => $answer]);
            }
        }

        return $session;
    }

    public static function engines(): array
    {
        return array_map(fn ($engine) => [$engine], ['matching', 'word_builder', 'quick_challenge', 'memory_flip', 'fill_gap']);
    }

    #[DataProvider('engines')]
    public function test_each_engine_has_persistent_results_and_server_rewards(string $engine): void
    {
        [, $student, , $round, $assignment, $content] = $this->fixture($engine);
        $this->withHeaders($this->headers($student));
        $session = $this->postJson('/api/arcade/rounds/'.$round.'/start', ['assignment_id' => $assignment])->assertOk()->json();
        $started = $this->act($session, ['kind' => 'enter', 'activity_id' => $content['activities'][0]['id']]);
        $this->assertArrayNotHasKey('accepted_answers', $started['current_activity']['item'] ?? []);
        $this->assertArrayNotHasKey('accepted_orders', $started['current_activity']['item'] ?? []);
        if ($engine === 'memory_flip') {
            $this->assertSame([null], array_values(array_unique(array_column($started['current_activity']['cards'], 'text'))));
        }
        $session = $this->complete($started, $content['activities'][0]);
        $this->assertSame('completed', $session['status']);
        $this->assertGreaterThan(0, $session['round_result']['accuracy']);
        $this->assertDatabaseHas('arcade_results', ['session_id' => $session['id'], 'student_id' => $student->id, 'engine' => $engine]);
        $this->assertDatabaseHas('student_achievements', ['student_id' => $student->id, 'code' => 'first_game_completed']);
        $this->getJson('/api/arcade/sessions/'.$session['id'])->assertOk()->assertJsonPath('status', 'completed');
        $this->getJson('/api/arcade/hub')->assertOk()->assertJsonPath('completed_rounds', 1);
    }

    public function test_linear_round_preserves_stages_and_never_duplicates_rewards_on_retry(): void
    {
        [, $student, , $round, $assignment, $content] = $this->fixture();
        $this->withHeaders($this->headers($student));
        $session = $this->postJson('/api/arcade/rounds/'.$round.'/start', ['assignment_id' => $assignment])->assertOk()->json();
        $this->postJson('/api/arcade/sessions/'.$session['id'].'/actions', ['event_key' => 'locked-test-event', 'revision' => 0, 'action' => ['kind' => 'enter', 'activity_id' => $content['activities'][1]['id']]])->assertForbidden();
        foreach ($content['activities'] as $activity) {
            $session = $this->complete($session, $activity);
        }
        $this->assertSame('completed', $session['status']);
        $this->assertCount(5, $session['round_result']['results']);
        $this->assertDatabaseCount('arcade_results', 5);
        $xp = DB::table('arcade_rewards')->where('student_id', $student->id)->sum('amount');
        $this->assertGreaterThan(0, $xp);
        $session2 = $this->postJson('/api/arcade/rounds/'.$round.'/start', ['assignment_id' => $assignment])->assertOk()->json();
        foreach ($content['activities'] as $activity) {
            $session2 = $this->complete($session2, $activity);
        }
        $this->assertLessThanOrEqual(10, $session2['round_result']['xp_awarded']);
        $this->assertSame($xp + $session2['round_result']['xp_awarded'], DB::table('arcade_rewards')->where('student_id', $student->id)->sum('amount'));
        $this->assertSame(5, DB::table('arcade_rewards')->where('student_id', $student->id)->where('reward_key', 'like', 'activity:%')->count());
        $this->assertSame(1, DB::table('arcade_rewards')->where('student_id', $student->id)->where('reward_key', 'round')->count());
    }

    public function test_replayed_answer_returns_receipt_without_reapplying_the_answer(): void
    {
        [, $student, , $round, $assignment, $content] = $this->fixture('quick_challenge');
        $this->withHeaders($this->headers($student));
        $s = $this->postJson('/api/arcade/rounds/'.$round.'/start', ['assignment_id' => $assignment])->assertOk()->json();
        $a = $content['activities'][0];
        $s = $this->act($s, ['kind' => 'enter', 'activity_id' => $a['id']]);
        $q = $a['items'][0];
        $body = ['event_key' => 'network-retry-unique', 'revision' => $s['revision'], 'action' => ['kind' => 'answer', 'activity_id' => $a['id'], 'item_id' => $q['id'], 'answer' => $q['accepted_answers'][0]], 'score' => 999999, 'xp' => 999999];
        $first = $this->postJson('/api/arcade/sessions/'.$s['id'].'/actions', $body)->assertOk()->json();
        $this->postJson('/api/arcade/sessions/'.$s['id'].'/actions', $body)->assertOk()->assertJsonPath('revision', $first['revision']);
        $this->assertSame(1, json_decode(DB::table('arcade_sessions')->find($s['id'])->progress, true)['activities'][$a['id']]['items'][$q['id']]['tries']);
        $this->assertDatabaseCount('arcade_rewards', 0);
        $body['action']['answer'] = 'tampered';
        $this->postJson('/api/arcade/sessions/'.$s['id'].'/actions', $body)->assertConflict();
    }

    public function test_drafts_and_active_session_survive_resume_and_new_publications(): void
    {
        [$teacher, $student, , $round, $assignment, $content] = $this->fixture('word_builder');
        $this->withHeaders($this->headers($student));
        $s = $this->postJson('/api/arcade/rounds/'.$round.'/start', ['assignment_id' => $assignment])->assertOk()->json();
        $a = $content['activities'][0];
        $s = $this->act($s, ['kind' => 'enter', 'activity_id' => $a['id']]);
        $this->putJson('/api/arcade/sessions/'.$s['id'].'/draft', ['activity_id' => $a['id'], 'item_id' => $a['items'][0]['id'], 'answer' => ['t0', 't1']])->assertOk();
        $this->getJson('/api/arcade/sessions/'.$s['id'])->assertOk()->assertJsonPath('current_activity.item.draft', ['t0', 't1']);
        $this->postJson('/api/arcade/rounds/'.$round.'/start', ['assignment_id' => $assignment])->assertOk()->assertJsonPath('id', $s['id']);
        $this->withHeaders($this->headers($teacher));
        $changed = $content;
        $changed['activities'][0]['items'][0]['prompt'] = 'Một câu hỏi mới của bản nháp';
        $this->putJson('/api/arcade/rounds/'.$round, ['content' => $changed, 'expected_revision' => 1])->assertOk();
        $this->postJson('/api/arcade/rounds/'.$round.'/publish')->assertUnprocessable();
        $this->postJson('/api/arcade/rounds/'.$round.'/preview')->assertOk();
        $this->postJson('/api/arcade/rounds/'.$round.'/publish')->assertOk();
        $this->withHeaders($this->headers($student));
        $this->getJson('/api/arcade/sessions/'.$s['id'])->assertOk()->assertJsonPath('version_id', $s['version_id'])->assertJsonPath('current_activity.item.prompt', $a['items'][0]['prompt']);
        $this->assertDatabaseCount('arcade_versions', 2);
    }

    public function test_teacher_can_save_incomplete_private_draft_but_cannot_preview_or_publish_it(): void
    {
        [$teacher, , , $round, , $content] = $this->fixture();
        $content['activities'] = [];
        $content['objectives'] = '';
        $this->withHeaders($this->headers($teacher));
        $this->putJson('/api/arcade/rounds/'.$round, ['content' => $content, 'expected_revision' => 1])->assertOk();
        $this->postJson('/api/arcade/rounds/'.$round.'/preview')->assertUnprocessable();
        $this->postJson('/api/arcade/rounds/'.$round.'/publish')->assertUnprocessable();
        $this->getJson('/api/arcade/rounds/'.$round)->assertOk()->assertJsonPath('content.activities', []);
    }

    public function test_preview_has_real_mechanics_without_student_results_or_xp(): void
    {
        [$teacher, , , $round, , $content] = $this->fixture('matching');
        $this->withHeaders($this->headers($teacher));
        $s = $this->postJson('/api/arcade/rounds/'.$round.'/preview')->assertOk()->json();
        $s = $this->complete($s, $content['activities'][0]);
        $this->assertSame(0, $s['round_result']['xp_awarded']);
        $this->assertDatabaseCount('arcade_results', 0);
        $this->assertDatabaseCount('arcade_rewards', 0);
        $this->assertDatabaseCount('student_achievements', 0);
    }

    public function test_timed_activity_uses_server_deadline_and_enforces_attempt_limit(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00', 'Asia/Ho_Chi_Minh'));
        [$teacher, $student, $class, $round, , $content] = $this->fixture('quick_challenge');
        $content['activities'][0]['timed'] = true;
        $content['activities'][0]['time_limit_seconds'] = 30;
        $this->withHeaders($this->headers($teacher));
        $this->putJson('/api/arcade/rounds/'.$round, ['content' => $content, 'expected_revision' => 1])->assertOk();
        $this->postJson('/api/arcade/rounds/'.$round.'/preview')->assertOk();
        $this->postJson('/api/arcade/rounds/'.$round.'/publish')->assertOk();
        $assignment = $this->postJson('/api/arcade/rounds/'.$round.'/assign', ['class_id' => $class, 'allowed_attempts' => 1])->assertCreated()->json('id');
        $this->withHeaders($this->headers($student));
        $s = $this->postJson('/api/arcade/rounds/'.$round.'/start', ['assignment_id' => $assignment])->assertOk()->json();
        $a = $content['activities'][0];
        $s = $this->act($s, ['kind' => 'enter', 'activity_id' => $a['id']]);
        $this->assertSame('2026-10-15T10:00:30+07:00', $s['current_activity']['state']['expires_at']);
        $this->travel(31)->seconds();
        $s = $this->act($s, ['kind' => 'answer', 'activity_id' => $a['id'], 'item_id' => $a['items'][0]['id'], 'answer' => $a['items'][0]['accepted_answers'][0]]);
        $this->assertSame(0, $s['round_result']['accuracy']);
        $this->assertSame(0, $s['round_result']['xp_awarded']);
        $this->postJson('/api/arcade/rounds/'.$round.'/start', ['assignment_id' => $assignment])->assertUnprocessable();
    }

    public function test_mistakes_improvement_reports_and_csv_use_persisted_results(): void
    {
        [$teacher, $student, $class, $round, $assignment, $content] = $this->fixture('fill_gap');
        $this->withHeaders($this->headers($student));
        $s = $this->postJson('/api/arcade/rounds/'.$round.'/start', ['assignment_id' => $assignment])->assertOk()->json();
        $a = $content['activities'][0];
        $s = $this->act($s, ['kind' => 'enter', 'activity_id' => $a['id']]);
        $s = $this->act($s, ['kind' => 'answer', 'activity_id' => $a['id'], 'item_id' => $a['items'][0]['id'], 'answer' => 'incorrect']);
        $this->assertFalse($s['feedback']['correct']);
        $s = $this->complete($s, $a);
        $this->assertEqualsWithDelta(66.67, $s['round_result']['accuracy'], 0.01);
        $this->getJson('/api/arcade/mistakes?resolved=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.review.prompt', $a['items'][0]['prompt']);
        $again = $this->postJson('/api/arcade/rounds/'.$round.'/start', ['assignment_id' => $assignment])->assertOk()->json();
        $this->complete($again, $a);
        $this->withHeaders($this->headers($teacher));
        $report = $this->getJson('/api/arcade/reports?class_id='.$class.'&round_id='.$round)->assertOk()->assertJsonPath('summary.assigned_students', 1)->assertJsonPath('summary.completed_students', 1)->assertJsonPath('students.data.0.improvement', 33.33);
        $this->assertGreaterThan(66.67, $report->json('summary.average_accuracy'));
        $export = $this->get('/api/arcade/reports/export?round_id='.$round)->assertOk();
        $this->assertStringContainsString($student->name, $export->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'arcade_results', 'action' => 'exported']);
    }

    public function test_cross_tenant_and_unenrolled_users_cannot_read_or_play_rounds(): void
    {
        [$teacher, $student, , $round, $assignment] = $this->fixture();
        $this->withHeaders($this->headers($student));
        $s = $this->postJson('/api/arcade/rounds/'.$round.'/start', ['assignment_id' => $assignment])->assertOk()->json();
        $this->getJson('/api/arcade/rounds/'.$round)->assertForbidden();
        $this->getJson('/api/arcade/reports')->assertForbidden();
        $other = User::factory()->create(['role' => 'student']);
        $this->withHeaders($this->headers($other));
        $this->postJson('/api/arcade/rounds/'.$round.'/start')->assertNotFound();
        $this->getJson('/api/arcade/sessions/'.$s['id'])->assertNotFound();
        $org = DB::table('organizations')->insertGetId(['name' => 'Other school', 'slug' => 'other-arcade', 'created_at' => now(), 'updated_at' => now()]);
        $outsider = User::factory()->create(['role' => 'admin', 'organization_id' => $org]);
        $this->withHeaders($this->headers($outsider));
        $this->getJson('/api/arcade/rounds/'.$round)->assertNotFound();
        $this->postJson('/api/arcade/rounds/'.$round.'/preview')->assertNotFound();
    }
}
