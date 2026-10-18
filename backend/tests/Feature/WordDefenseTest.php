<?php

namespace Tests\Feature;

use App\ArcadeContent;
use App\ArcadeTemplates;
use App\Models\User;
use App\WordDefenseBattle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WordDefenseTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        $token = 'defense-test-'.$user->id;
        DB::table('api_tokens')->insertOrIgnore(['organization_id' => $user->organization_id, 'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        return ['Authorization' => 'Bearer '.$token];
    }

    private function fixture(): array
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $year = DB::table('school_years')->insertGetId(['name' => '2026–2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $subject = DB::table('subjects')->insertGetId(['name' => 'Tiếng Anh', 'color' => '#d9eaff', 'created_at' => now(), 'updated_at' => now()]);
        $class = DB::table('school_classes')->insertGetId(['name' => '5D', 'subject_id' => $subject, 'teacher_id' => $teacher->id, 'school_year_id' => $year, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('class_memberships')->insert(['class_id' => $class, 'student_id' => $student->id, 'created_at' => now(), 'updated_at' => now()]);
        $definition = collect(ArcadeTemplates::all())->firstWhere('id', 'word-defense')['content'];
        $definition['subject_id'] = $subject;
        $definition['activities'] = [$definition['activities'][0]];
        $this->withHeaders($this->headers($teacher));
        $round = $this->postJson('/api/arcade/rounds', ['content' => $definition, 'expected_revision' => 0])->assertCreated()->json('id');
        $this->postJson('/api/arcade/rounds/'.$round.'/preview')->assertOk();
        $this->postJson('/api/arcade/rounds/'.$round.'/publish')->assertOk();
        $assignment = $this->postJson('/api/arcade/rounds/'.$round.'/assign', ['class_id' => $class, 'allowed_attempts' => 3])->assertCreated()->json('id');
        $this->withHeaders($this->headers($student));
        $session = $this->postJson('/api/arcade/rounds/'.$round.'/start', ['assignment_id' => $assignment])->assertOk()->json();
        $session = $this->act($session, ['kind' => 'enter']);

        return [$session, $definition['activities'][0], $student];
    }

    private function act(array $session, array $action, ?string $event = null): array
    {
        return $this->postJson('/api/arcade/sessions/'.$session['id'].'/actions', ['event_key' => $event ?? 'defense-'.bin2hex(random_bytes(8)), 'revision' => $session['revision'], 'action' => ['activity_id' => 'defense_1', ...$action]])->assertOk()->json();
    }

    public function test_movement_projectile_collisions_pause_and_private_content(): void
    {
        $this->freezeTime();
        [$session] = $this->fixture();
        $this->assertArrayNotHasKey('pairs', $session['current_activity']);
        $battle = $session['current_activity']['battle'];
        $this->assertArrayNotHasKey('plan', $battle);
        $this->assertArrayNotHasKey('pair', $battle['cards'][0]);
        $session = $this->act($session, ['kind' => 'defense_resume']);
        $this->travel(3)->seconds();
        $session = $this->act($session, ['kind' => 'defense_sync']);
        $enemy = $session['current_activity']['battle']['enemies'][0];
        $this->assertGreaterThan(0, $enemy['position']);
        $this->assertArrayNotHasKey('pair', $enemy);
        $card = collect($battle['cards'])->firstWhere('text', 'book');
        $session = $this->act($session, ['kind' => 'defense_deploy', 'spell_id' => $card['id'], 'lane' => 0]);
        $this->assertSame(30, $session['current_activity']['battle']['enemies'][0]['health']);
        $this->travel(1)->seconds();
        $session = $this->act($session, ['kind' => 'defense_sync']);
        $this->assertSame(8, $session['current_activity']['battle']['enemies'][0]['health']);
        $session = $this->act($session, ['kind' => 'defense_pause']);
        $paused = $session['current_activity']['battle']['time'];
        $this->travel(30)->seconds();
        $session = $this->act($session, ['kind' => 'defense_sync']);
        $this->assertEquals($paused, $session['current_activity']['battle']['time']);
    }

    public function test_score_forgery_invalid_lane_and_another_students_session_are_rejected(): void
    {
        [$session] = $this->fixture();
        $body = ['event_key' => 'invalid-defence', 'revision' => $session['revision'], 'action' => ['activity_id' => 'defense_1', 'kind' => 'defense_sync', 'score' => 999999]];
        $this->postJson('/api/arcade/sessions/'.$session['id'].'/actions', $body)->assertUnprocessable()->assertJsonValidationErrors('action');
        unset($body['action']['score']);
        $body['action']['lane'] = 7;
        $this->postJson('/api/arcade/sessions/'.$session['id'].'/actions', $body)->assertUnprocessable()->assertJsonValidationErrors('action.lane');
        $other = User::factory()->create(['role' => 'student']);
        $this->withHeaders($this->headers($other))->getJson('/api/arcade/sessions/'.$session['id'])->assertNotFound();
    }

    public function test_real_defeat_persists_zero_stars_and_cannot_claim_a_win(): void
    {
        $this->freezeTime();
        [$session, , $student] = $this->fixture();
        $session = $this->act($session, ['kind' => 'defense_resume']);
        for ($i = 0; $i < 100 && $session['status'] === 'active'; $i++) {
            $this->travel(1)->seconds();
            $session = $this->act($session, ['kind' => 'defense_sync']);
        }
        $this->assertSame('completed', $session['status']);
        $this->assertSame('lost', $session['round_result']['results'][0]['battle']['status']);
        $this->assertSame(0, $session['round_result']['stars']);
        $this->assertSame(0, $session['round_result']['xp_awarded']);
        $this->assertFalse($session['round_result']['met_requirement']);
        $this->assertDatabaseHas('arcade_results', ['student_id' => $student->id, 'session_id' => $session['id'], 'engine' => 'word_defense', 'stars' => 0]);
    }

    public function test_winning_battle_is_saved_and_duplicate_final_event_does_not_reward_twice(): void
    {
        $this->freezeTime();
        [$session, $activity, $student] = $this->fixture();
        $session = $this->act($session, ['kind' => 'defense_resume']);
        $key = '';
        for ($i = 0; $i < 100 && $session['status'] === 'active'; $i++) {
            $this->travel(1)->seconds();
            $key = 'tick-final-'.$i;
            $prior = $session['revision'];
            $session = $this->act($session, ['kind' => 'defense_sync'], $key);
            if ($session['status'] !== 'active') {
                break;
            }
            $b = $session['current_activity']['battle'];
            for ($lane = 0; $lane < 3; $lane++) {
                $targets = collect($b['enemies'])->where('lane', $lane)->sortByDesc('position');
                $enemy = $targets->first();
                if (! $enemy) {
                    continue;
                }
                $pair = collect($activity['pairs'])->firstWhere('right', $enemy['clue']);
                $card = collect($b['cards'])->firstWhere('text', $pair['left']);
                if ($i === 2 && $lane === 0) {
                    $card = collect($b['cards'])->firstWhere('text', 'teacher');
                }
                $tower = collect($b['towers'])->firstWhere('lane', $lane);
                if ((! $tower || $tower['text'] !== $card['text'] || $tower['until'] - $b['time'] < 1) && $b['energy'] >= 3 && ($b['card_ready'][$card['id']] ?? 0) <= $b['time']) {
                    $session = $this->act($session, ['kind' => 'defense_deploy', 'lane' => $lane, 'spell_id' => $card['id']]);
                    $b = $session['current_activity']['battle'];
                }
            }
        }
        $this->assertSame('won', $session['round_result']['results'][0]['battle']['status']);
        $this->assertSame(3, $session['round_result']['stars']);
        $this->assertGreaterThan(0, $session['round_result']['xp_awarded']);
        $review = collect($session['round_result']['results'][0]['review'])->firstWhere('prompt', 'quyển sách');
        $this->assertFalse($review['first_correct']);
        $this->assertTrue($review['correct']);
        $this->assertDatabaseHas('arcade_results', ['student_id' => $student->id, 'session_id' => $session['id'], 'stars' => 3]);
        $rewards = DB::table('arcade_rewards')->where('student_id', $student->id)->sum('amount');
        $this->postJson('/api/arcade/sessions/'.$session['id'].'/actions', ['event_key' => $key, 'revision' => $prior, 'action' => ['activity_id' => 'defense_1', 'kind' => 'defense_sync']])->assertOk();
        $this->assertEquals($rewards, DB::table('arcade_rewards')->where('student_id', $student->id)->sum('amount'));
    }

    public function test_all_five_stage_simulations_are_winnable_and_boss_summons_have_distinct_behavior(): void
    {
        $activities = collect(ArcadeTemplates::all())->firstWhere('id', 'word-defense')['content']['activities'];
        $engine = new WordDefenseBattle;
        foreach ($activities as $activity) {
            $b = $engine->initial($activity, 'test-seed', 0);
            $engine->command($b, $activity, ['kind' => 'defense_resume']);
            $bossSeen = false;
            for ($tick = 1; $tick < 1500 && $b['status'] === 'playing'; $tick++) {
                $engine->advance($b, $activity, $tick / 10);
                foreach ([0, 1, 2] as $lane) {
                    $targets = array_values(array_filter($b['enemies'], fn ($e) => $e['lane'] === $lane));
                    usort($targets, fn ($a, $c) => $c['position'] <=> $a['position']);
                    $enemy = $targets[0] ?? null;
                    if (! $enemy) {
                        continue;
                    }
                    $bossSeen = $bossSeen || $enemy['kind'] === 'boss';
                    $tower = collect($b['towers'])->firstWhere('lane', $lane);
                    $card = collect($b['cards'])->firstWhere('pair', $enemy['pair']);
                    if ((! $tower || $tower['pair'] !== $enemy['pair'] || $tower['until'] - $b['time'] < .2) && $b['energy'] >= 3 && ($b['card_ready'][$card['id']] ?? 0) <= $b['time']) {
                        $engine->command($b, $activity, ['kind' => 'defense_deploy', 'lane' => $lane, 'spell_id' => $card['id']]);
                    }
                }
            }
            $this->assertSame('won', $b['status'], 'Stage '.$b['stage']);
            if ($b['stage'] === 5) {
                $this->assertTrue($bossSeen);
                $this->assertGreaterThan(20, $b['total'], 'Boss summons add real enemies that must also be defeated.');
            }
        }
    }

    public function test_incompatible_banks_are_reported_instead_of_inventing_vocabulary(): void
    {
        $content = new ArcadeContent;
        $converted = $content->bankActivities([['type' => 'ordering', 'prompt' => 'Arrange this', 'options' => ['a', 'b'], 'answer' => ['a', 'b']]], 'word_defense');
        $this->assertSame(0, $converted['compatible_count']);
        $this->assertCount(1, $converted['rejected']);
        $incomplete = $content->bankActivities([['type' => 'matching', 'prompt' => 'Incomplete', 'options' => ['left' => ['a', 'b', 'c', 'd']], 'answer' => ['a' => 'A']]], 'word_defense');
        $this->assertSame(0, $incomplete['compatible_count']);
        $valid = $content->bankActivities([['type' => 'matching', 'prompt' => 'Vocabulary', 'options' => ['left' => ['a', 'b', 'c', 'd']], 'answer' => ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D']]], 'word_defense');
        $this->assertSame(1, $valid['compatible_count']);
        $this->assertSame('word_defense', $valid['activities'][0]['engine']);
    }

    public function test_mismatch_damage_upgrades_and_ability_costs_and_cooldowns_are_authoritative(): void
    {
        $this->freezeTime();
        [$session] = $this->fixture();
        $session = $this->act($session, ['kind' => 'defense_resume']);
        $this->travel(3)->seconds();
        $session = $this->act($session, ['kind' => 'defense_sync']);
        $card = collect($session['current_activity']['battle']['cards'])->firstWhere('text', 'teacher');
        $session = $this->act($session, ['kind' => 'defense_deploy', 'lane' => 0, 'spell_id' => $card['id']]);
        $this->travel(1)->seconds();
        $session = $this->act($session, ['kind' => 'defense_sync']);
        $this->assertSame(28, $session['current_activity']['battle']['enemies'][0]['health']);
        $this->assertSame(1, $session['current_activity']['battle']['wrong']);
        $session = $this->act($session, ['kind' => 'defense_upgrade', 'lane' => 0]);
        $this->assertSame(2, $session['current_activity']['battle']['towers'][0]['level']);
        $energy = $session['current_activity']['battle']['energy'];
        $session = $this->act($session, ['kind' => 'defense_ability']);
        $b = $session['current_activity']['battle'];
        $this->assertEquals($energy - 4, $b['energy']);
        $this->assertGreaterThan($b['time'], $b['freeze_until']);
        $this->postJson('/api/arcade/sessions/'.$session['id'].'/actions', ['event_key' => 'ability-too-soon', 'revision' => $session['revision'], 'action' => ['kind' => 'defense_ability', 'activity_id' => 'defense_1']])->assertUnprocessable();
    }

    public function test_three_tower_roles_charge_their_own_costs_and_reject_unaffordable_deployment(): void
    {
        $this->freezeTime();
        [$session] = $this->fixture();
        $session = $this->act($session, ['kind' => 'defense_resume']);
        $cards = collect($session['current_activity']['battle']['cards']);
        $book = $cards->firstWhere('text', 'book');
        $pencil = $cards->firstWhere('text', 'pencil');
        $classroom = $cards->firstWhere('text', 'classroom');

        $session = $this->act($session, ['kind' => 'defense_deploy', 'lane' => 0, 'spell_id' => $book['id']]);
        $this->assertSame(7, $session['current_activity']['battle']['energy']);
        $session = $this->act($session, ['kind' => 'defense_deploy', 'lane' => 1, 'spell_id' => $pencil['id']]);
        $this->assertSame(5, $session['current_activity']['battle']['energy']);
        $session = $this->act($session, ['kind' => 'defense_deploy', 'lane' => 2, 'spell_id' => $classroom['id']]);
        $battle = $session['current_activity']['battle'];
        $this->assertSame(1, $battle['energy']);
        $this->assertSame(['scribe', 'spark', 'cannon'], array_column($battle['towers'], 'type'));
        $this->assertSame([9, 8, 12], array_column($battle['towers'], 'until'));
        $this->assertSame(1.5, $battle['card_ready'][$pencil['id']]);

        $this->postJson('/api/arcade/sessions/'.$session['id'].'/actions', ['event_key' => 'cannot-overspend', 'revision' => $session['revision'], 'action' => ['activity_id' => 'defense_1', 'kind' => 'defense_deploy', 'lane' => 0, 'spell_id' => $classroom['id']]])->assertUnprocessable();
        $saved = $this->getJson('/api/arcade/sessions/'.$session['id'])->assertOk()->json('current_activity.battle');
        $this->assertSame(1, $saved['energy']);
        $this->assertSame($battle['towers'], $saved['towers']);
    }
}
