<?php

namespace App\Http\Controllers;

use App\LearningAccess;
use App\QuestionEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GameProgressController extends Controller
{
    public function __construct(private LearningAccess $access, private QuestionEngine $engine) {}

    public function start(int $id): JsonResponse
    {
        $this->access->requireRole('student');
        $session = DB::transaction(function () use ($id) {
            $game = $this->access->table('games')->where('id', $id)->lockForUpdate()->first();
            abort_unless($game && ! $game->archived_at, 404);
            $this->access->classAccess($game->class_id);
            abort_if($game->due_at && now()->greaterThan($game->due_at), 422, 'Vòng chơi đã hết hạn.');
            $xp = $this->access->totalXp();
            abort_if($xp < $game->required_xp, 422, 'Chưa đủ XP để mở vòng chơi.');
            $query = $this->access->table('game_sessions')->where('game_id', $id)->where('student_id', $this->access->user()->id);
            $active = (clone $query)->whereNull('completed_at')->where('expires_at', '>', now())->first();
            if ($active) {
                return $active;
            }
            abort_if($game->allowed_attempts > 0 && (clone $query)->count() >= $game->allowed_attempts, 422, 'Đã hết số lượt chơi.');
            $questions = $this->access->table('questions')->where('game_id', $id)->orderBy('sort_order')->get()->map(function ($q) use ($game) {
                $row = (array) $q;
                $row['options'] = json_decode($q->options, true);
                $row['answer'] = json_decode($q->answer, true);
                $row['type'] = $q->kind !== 'single' ? $q->kind : match ($game->type) {
                    'matching' => 'matching', 'ordering' => 'ordering', 'fill_blank' => 'fill_blank', 'word_builder' => 'word_builder', default => 'single'
                };

                return $row;
            })->all();
            abort_unless($questions, 422, 'Vòng chơi chưa có câu hỏi.');
            $expires = now()->addSeconds($game->time_limit_seconds ?: 600);
            if ($game->due_at && $expires->greaterThan($game->due_at)) {
                $expires = Carbon::parse($game->due_at);
            }
            $sessionId = $this->access->table('game_sessions')->insertGetId(['game_id' => $id, 'student_id' => $this->access->user()->id, 'question_snapshot' => json_encode($questions, JSON_UNESCAPED_UNICODE), 'started_at' => now(), 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);

            return $this->access->table('game_sessions')->where('id', $sessionId)->first();
        });
        $questions = json_decode($session->question_snapshot, true);
        $questions = array_map(function ($q) {
            unset($q['answer']);

            return $q;
        }, $questions);

        return response()->json(['session_id' => $session->id, 'expires_at' => Carbon::parse($session->expires_at)->toIso8601String(), 'time_limit_seconds' => max(0, (int) now()->diffInSeconds($session->expires_at, false)), 'questions' => $questions]);
    }

    public function finish(Request $request, int $id): JsonResponse
    {
        $this->access->requireRole('student');
        $data = $request->validate(['session_id' => ['required', 'integer'], 'answers' => ['present', 'array', 'max:100']]);
        $result = DB::transaction(function () use ($data, $id) {
            $game = $this->access->table('games')->where('id', $id)->first();
            abort_unless($game, 404);
            $this->access->classAccess($game->class_id);
            $session = $this->access->table('game_sessions')->where('id', $data['session_id'])->where('game_id', $id)->where('student_id', $this->access->user()->id)->lockForUpdate()->first();
            abort_unless($session && ! $session->completed_at, 422, 'Phiên chơi không hợp lệ hoặc đã được nộp.');
            abort_if(now()->greaterThan(Carbon::parse($session->expires_at)->addSeconds(5)), 422, 'Đã hết thời gian làm bài.');
            $questions = json_decode($session->question_snapshot, true);
            if (! $questions) {
                $questions = $this->access->table('questions')->where('game_id', $id)->orderBy('sort_order')->get()->map(fn ($q) => ['id' => $q->id, 'type' => 'single', 'answer' => json_decode($q->answer, true), 'topic' => $q->topic, 'prompt' => $q->prompt])->all();
            }
            $correct = 0;
            $streak = 0;
            $best = 0;
            $wrong = [];
            $feedback = [];
            foreach ($questions as $q) {
                $ok = $this->engine->evaluate($q, $data['answers'][$q['id']] ?? null) === true;
                $correct += $ok ? 1 : 0;
                $streak = $ok ? $streak + 1 : 0;
                $best = max($best, $streak);
                if (! $ok) {
                    $wrong[] = $q['topic'] ?: $q['prompt'];
                }
                $feedback[] = ['id' => $q['id'], 'correct' => $ok, 'answer' => $q['answer']];
            }
            $total = count($questions);
            $remaining = max(0, (int) now()->diffInSeconds($session->expires_at, false));
            $points = $this->engine->gamePoints($correct, $total, $remaining, $best);
            $score = $total ? round(10 * $correct / $total, 2) : 0;
            $claimed = $this->access->table('game_sessions')->where('id', $session->id)->whereNull('completed_at')->update(['completed_at' => now()]);
            abort_unless($claimed === 1, 422, 'Phiên chơi đã được nộp.');
            $attemptId = $this->access->table('game_attempts')->insertGetId(['game_id' => $id, 'student_id' => $this->access->user()->id, 'answers' => json_encode($data['answers'], JSON_UNESCAPED_UNICODE), 'score' => $score, 'points' => $points, 'scoring_version' => 'eduquest-v1', 'correct_count' => $correct, 'incorrect_count' => $total - $correct, 'duration_seconds' => max(0, (int) now()->diffInSeconds($session->started_at, true)), 'wrong_topics' => json_encode($wrong, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            $xp = max(0, (int) round(50 * $correct / max(1, $total)));
            $awarded = $correct > 0 ? $this->access->table('xp_transactions')->insertOrIgnore(['student_id' => $this->access->user()->id, 'game_id' => $id, 'amount' => $xp, 'created_at' => now(), 'updated_at' => now()]) : 0;
            if ($correct === $total && $total > 0) {
                $new = $this->access->table('student_achievements')->insertOrIgnore(['student_id' => $this->access->user()->id, 'code' => 'perfect_round', 'created_at' => now(), 'updated_at' => now()]);
                if ($new) {
                    $this->access->notify([$this->access->user()->id], 'Huy hiệu mới', 'Hoàn thành một vòng chơi chính xác 100%!', 'achievement');
                }
            }

            return ['attempt_id' => $attemptId, 'score' => $score, 'points' => $points, 'correct_count' => $correct, 'incorrect_count' => $total - $correct, 'wrong_topics' => $wrong, 'xp_awarded' => $awarded ? $xp : 0, 'feedback' => $feedback, 'scoring_version' => 'eduquest-v1'];
        });

        return response()->json($result);
    }

    public function profile(): JsonResponse
    {
        $this->access->requireRole('student');
        $userId = $this->access->user()->id;
        $xp = $this->access->totalXp($userId);
        $history = $this->access->table('game_attempts')->join('games', 'game_attempts.game_id', '=', 'games.id')->where('student_id', $userId)->select('game_attempts.*', 'games.title')->orderByDesc('game_attempts.id')->limit(50)->get();
        $days = $this->access->table('game_attempts')->where('student_id', $userId)->selectRaw('DATE(created_at) AS practice_day')->distinct()->orderByDesc('practice_day')->limit(366)->pluck('practice_day')->all();
        $cursor = now()->startOfDay();
        if (! in_array($cursor->toDateString(), $days, true)) {
            $cursor->subDay();
        }
        $streak = 0;
        while (in_array($cursor->toDateString(), $days, true)) {
            $streak++;
            $cursor->subDay();
        }

        return response()->json(['xp' => $xp, 'level' => 1 + intdiv($xp, 200), 'streak' => $streak, 'achievements' => $this->access->table('student_achievements')->where('student_id', $userId)->get(), 'history' => $history]);
    }

    public function leaderboard(Request $request): JsonResponse
    {
        $this->access->requireRole('student', 'teacher', 'admin');
        $data = $request->validate(['class_id' => ['required', 'integer'], 'period' => ['nullable', 'in:week,month,all']]);
        $this->access->classAccess((int) $data['class_id']);
        $org = DB::table('organizations')->where('id', $this->access->user()->organization_id)->first();
        if (! $org->leaderboard_enabled) {
            return response()->json(['enabled' => false, 'rows' => []]);
        }
        $members = $this->access->table('class_memberships')->where('class_id', $data['class_id'])->pluck('student_id');
        $from = match ($data['period'] ?? 'week') {
            'month' => now()->startOfMonth(), 'all' => Carbon::create(2000), default => now()->startOfWeek()
        };
        $rows = $this->access->table('game_attempts')->join('games', 'game_attempts.game_id', '=', 'games.id')->where('games.class_id', $data['class_id'])->whereIn('student_id', $members)->where('game_attempts.created_at', '>=', $from)->groupBy('student_id')->selectRaw('student_id, MAX(points) AS points')->orderByDesc('points')->orderBy('student_id')->limit(50)->get()->map(function ($row) {
            return ['alias' => 'Nhà thám hiểm '.substr(hash_hmac('sha256', (string) $row->student_id, config('app.key')), 0, 6), 'points' => (int) $row->points, 'is_me' => $row->student_id === $this->access->user()->id];
        });

        return response()->json(['enabled' => true, 'rows' => $rows]);
    }
}
