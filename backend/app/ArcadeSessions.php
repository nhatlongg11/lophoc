<?php

namespace App;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ArcadeSessions
{
    public function __construct(private LearningAccess $access, private ArcadeContent $content) {}

    public function ownedRound(int $id): object
    {
        $this->access->requireRole('teacher', 'admin');
        $round = $this->access->table('arcade_rounds')->where('id', $id)->first();
        abort_unless($round && ($this->access->user()->role === 'admin' || $round->teacher_id === $this->access->user()->id), 404);

        return $round;
    }

    public function studentAssignments(): array
    {
        $id = $this->access->user()->id;

        return $this->access->table('arcade_assignments')->whereJsonContains('student_ids', $id)->whereNull('revoked_at')->orderByDesc('id')->get()->all();
    }

    private function eligibleAssignment(object $assignment): void
    {
        abort_unless(! $assignment->revoked_at && in_array($this->access->user()->id, json_decode($assignment->student_ids, true) ?: [], true), 404);
        abort_if($assignment->starts_at && now()->lessThan($assignment->starts_at), 422, 'Vòng học chưa đến thời gian mở.');
        abort_if($assignment->due_at && now()->greaterThanOrEqualTo($assignment->due_at), 422, 'Vòng học đã hết hạn. Phiên cũ và kết quả vẫn có trong lịch sử.');
    }

    public function session(int $id, bool $write = false): object
    {
        $session = $this->access->table('arcade_sessions')->where('id', $id)->where('student_id', $this->access->user()->id)->first();
        abort_unless($session, 404);
        if ($session->is_preview) {
            $this->ownedRound($session->round_id);
        } else {
            $this->access->requireRole('student');
            if ($write) {
                $assignment = $this->access->table('arcade_assignments')->find($session->assignment_id);
                abort_unless($assignment, 404);
                $this->eligibleAssignment($assignment);
                $round = $this->access->table('arcade_rounds')->find($session->round_id);
                abort_unless($round && ! $round->archived_at, 404);
            }
        }
        if ($write) {
            abort_unless($session->status === 'active', 409, 'Phiên này đã kết thúc. Xem kết quả hoặc bắt đầu lượt mới nếu được phép.');
            abort_if($session->expires_at && now()->greaterThanOrEqualTo($session->expires_at), 422, 'Phiên chơi đã hết hạn và không thể tiếp tục.');
        }

        return $session;
    }

    public function start(int $roundId, bool $preview = false, ?int $assignmentId = null): array
    {
        return DB::transaction(function () use ($roundId, $preview, $assignmentId) {
            $round = $this->access->table('arcade_rounds')->where('id', $roundId)->lockForUpdate()->first();
            abort_unless($round && ! $round->archived_at, 404);
            $assignment = null;
            if ($preview) {
                $this->ownedRound($roundId);
                $versionId = $round->draft_version_id;
            } else {
                $this->access->requireRole('student');
                $candidates = collect($this->studentAssignments())->where('round_id', $roundId);
                $assignment = $assignmentId ? $candidates->firstWhere('id', $assignmentId) : $candidates->first();
                abort_unless($assignment, 404);
                $this->eligibleAssignment($assignment);
                $versionId = $assignment->version_id;
            }
            $version = $this->access->table('arcade_versions')->where('round_id', $roundId)->find($versionId);
            abort_unless($version, 422, 'Vòng học chưa có nội dung hợp lệ.');
            $query = $this->access->table('arcade_sessions')->where('round_id', $roundId)->where('version_id', $versionId)->where('student_id', $this->access->user()->id)->where('is_preview', $preview)->when($assignment, fn ($q) => $q->where('assignment_id', $assignment->id));
            $active = (clone $query)->where('status', 'active')->first();
            if ($active && (! $active->expires_at || now()->lessThan($active->expires_at))) {
                return $this->view($active);
            }
            if ($active) {
                $this->access->table('arcade_sessions')->where('id', $active->id)->update(['status' => 'expired', 'updated_at' => now()]);
            }
            abort_if(! $preview && (clone $query)->count() >= $assignment->allowed_attempts, 422, 'Em đã dùng hết số lượt được giao. Liên hệ giáo viên để được hỗ trợ.');
            $content = json_decode($version->content, true);
            $states = [];
            foreach ($content['activities'] as $i => $a) {
                $states[$a['id']] = ['status' => ! $content['linear'] || $i === 0 ? 'available' : 'locked', 'started_at' => null, 'expires_at' => null, 'matched' => [], 'flipped' => [], 'resolving' => false, 'flips' => 0, 'wrong' => 0, 'hints' => 0, 'actions' => 0, 'items' => [], 'responses' => []];
            }
            $id = $this->access->table('arcade_sessions')->insertGetId(['round_id' => $roundId, 'version_id' => $versionId, 'assignment_id' => $assignment?->id, 'student_id' => $this->access->user()->id, 'is_preview' => $preview, 'progress' => json_encode(['activities' => $states, 'current' => null, 'drafts' => [], 'events' => 0, 'round_result' => null]), 'started_at' => now(), 'expires_at' => $preview ? now()->addHour() : $assignment?->due_at, 'created_at' => now(), 'updated_at' => now()]);

            return $this->view($this->access->table('arcade_sessions')->find($id));
        });
    }

    public function definition(object $session): array
    {
        $definition = json_decode($this->access->table('arcade_versions')->find($session->version_id)->content, true);
        if ($definition['randomize_questions'] ?? false) {
            foreach ($definition['activities'] as &$activity) {
                if (isset($activity['items'])) {
                    $activity['items'] = $this->content->shuffled($activity['items'], 'questions-'.$session->id.'-'.$activity['id']);
                }
            }
            unset($activity);
        }

        return $definition;
    }

    public function view(object $session, ?array $feedback = null): array
    {
        $definition = $this->definition($session);
        $progress = json_decode($session->progress, true);
        $current = collect($definition['activities'])->firstWhere('id', $progress['current']);

        return ['id' => $session->id, 'round_id' => $session->round_id, 'version_id' => $session->version_id, 'assignment_id' => $session->assignment_id, 'title' => $definition['title'], 'objectives' => $definition['objectives'], 'linear' => $definition['linear'], 'is_preview' => (bool) $session->is_preview, 'status' => $session->status, 'revision' => $session->revision, 'server_time' => now()->toIso8601String(), 'expires_at' => $session->expires_at ? Carbon::parse($session->expires_at)->toIso8601String() : null, 'activities' => array_map(fn ($a) => ['id' => $a['id'], 'engine' => $a['engine'], 'title' => $a['title'], 'topic' => $a['topic'], 'objective' => $a['objective'], 'difficulty' => $a['difficulty'], 'status' => $progress['activities'][$a['id']]['status'], 'result' => $progress['activities'][$a['id']]['result'] ?? null], $definition['activities']), 'current_activity' => $current ? $this->publicActivity($session, $current, $progress['activities'][$current['id']], $progress['drafts'][$current['id']] ?? []) : null, 'round_result' => $progress['round_result'], 'feedback' => $feedback];
    }

    private function cards(object $session, array $a): array
    {
        $cards = [];
        foreach ($a['pairs'] as $pair) {
            foreach (['left', 'right'] as $side) {
                $cards[] = ['id' => $this->content->cardId($session->id, $a['id'], $pair['id'], $side), 'pair' => $pair['id'], 'side' => $side, 'text' => $pair[$side], 'image' => $pair[$side.'_image'] ?? null, 'media' => $pair[$side.'_media'] ?? null];
            }
        }

        return $cards;
    }

    private function publicActivity(object $session, array $a, array $state, array $draft): array
    {
        $public = array_intersect_key($a, array_flip(['id', 'engine', 'title', 'topic', 'objective', 'difficulty', 'allow_hints', 'show_explanations', 'retry_limit', 'timed', 'time_limit_seconds']));
        $streak = 0;
        foreach (array_reverse($state['responses']) as $response) {
            if (! $response['correct']) {
                break;
            }
            $streak++;
        }
        $public['streak'] = $streak;
        $public['combo'] = min(3, $streak);
        $public['state'] = array_intersect_key($state, array_flip(['status', 'started_at', 'expires_at', 'matched', 'flipped', 'resolving', 'flips', 'wrong', 'hints', 'actions', 'result']));
        if ($a['engine'] === 'word_defense') {
            $public['battle'] = (new WordDefenseBattle)->publicState($state['battle'], $a);
            $public['total'] = $state['battle']['total'];
            $public['completed_items'] = $state['battle']['kills'] + $state['battle']['leaked'];

            return $public;
        }
        if (isset($a['pairs'])) {
            $cards = $this->content->shuffled($this->cards($session, $a), 'cards-'.$session->id.'-'.$a['id']);
            $public['cards'] = array_map(function ($c) use ($state, $a) {
                $shown = $a['engine'] === 'matching' || in_array($c['pair'], $state['matched'], true) || in_array($c['id'], $state['flipped'], true);

                return ['id' => $c['id'], 'side' => $a['engine'] === 'matching' ? $c['side'] : null, 'text' => $shown ? $c['text'] : null, 'image' => $shown ? $c['image'] : null, 'media' => $shown ? $c['media'] : null, 'revealed' => $shown, 'matched' => in_array($c['pair'], $state['matched'], true)];
            }, $cards);
            $public['total'] = count($a['pairs']);
        } else {
            $pending = array_values(array_filter($a['items'], fn ($q) => ! ($state['items'][$q['id']]['resolved'] ?? false)));
            $q = $pending[0] ?? null;
            $public['item'] = $q ? array_intersect_key($q, array_flip(['id', 'prompt', 'answer_mode', 'case_sensitive', 'numeric', 'media', 'media_url', 'choice_media', 'choice_ids'])) : null;
            if ($q) {
                $public['item']['hint_available'] = $a['allow_hints'] && ! empty($q['hint']);
                $public['item']['tries'] = $state['items'][$q['id']]['tries'] ?? 0;
                $public['item']['draft'] = $draft[$q['id']] ?? null;
                if (isset($q['tokens'])) {
                    $public['item']['tokens'] = $this->content->shuffled($q['tokens'], 'tokens-'.$session->id.'-'.$q['id']);
                } else {
                    $public['item']['choices'] = $this->content->shuffled($q['choices'], 'choices-'.$session->id.'-'.$q['id']);
                }
            }
            $public['completed_items'] = count($a['items']) - count($pending);
            $public['total'] = count($a['items']);
        }

        return $public;
    }

    public function action(int $sessionId, string $eventKey, int $revision, array $action): array
    {
        $this->session($sessionId);

        return DB::transaction(function () use ($sessionId, $eventKey, $revision, $action) {
            $session = $this->access->table('arcade_sessions')->where('id', $sessionId)->lockForUpdate()->first();
            $hash = hash('sha256', json_encode($action, JSON_UNESCAPED_UNICODE));
            $receipt = $this->access->table('arcade_events')->where('session_id', $sessionId)->where('event_key', $eventKey)->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->payload_hash, $hash), 409, 'Mã thao tác đã được dùng cho nội dung khác.');

                return $this->view($session, json_decode($receipt->feedback ?? 'null', true));
            }
            $this->session($sessionId, true);
            abort_unless($session->revision === $revision, 409, 'Tiến độ đã thay đổi ở một trang khác. Tải lại phiên để tiếp tục.');
            $definition = $this->definition($session);
            $progress = json_decode($session->progress, true);
            $limit = count(array_filter($definition['activities'], fn ($a) => $a['engine'] === 'word_defense')) ? 3000 : 1000;
            abort_if($progress['events'] >= $limit, 422, 'Phiên có quá nhiều thao tác. Liên hệ giáo viên để được hỗ trợ.');
            $activity = collect($definition['activities'])->firstWhere('id', $action['activity_id'] ?? null);
            abort_unless($activity, 422, 'Không tìm thấy hoạt động trong phiên này.');
            $key = $activity['id'];
            $state = $progress['activities'][$key];
            abort_if($state['status'] === 'locked', 403, 'Hoàn thành chặng trước để mở hoạt động này.');
            abort_if($state['status'] === 'completed', 409, 'Hoạt động này đã hoàn thành.');
            $feedback = null;
            $kind = $action['kind'] ?? '';
            if ($activity['engine'] === 'word_defense' && $kind !== 'enter') {
                abort_unless($progress['current'] === $key && $state['started_at'], 422, 'Mở trận đấu trước khi ra lệnh.');
                $engine = new WordDefenseBattle;
                $engine->advance($state['battle'], $activity, now()->getTimestampMs() / 1000);
                if ($state['battle']['status'] === 'playing') {
                    $engine->command($state['battle'], $activity, $action);
                }
                $state['actions'] += in_array($kind, ['defense_deploy', 'defense_upgrade', 'defense_ability'], true) ? 1 : 0;
            } elseif ($kind === 'enter') {
                $progress['current'] = $key;
                if (! $state['started_at']) {
                    $state['started_at'] = now()->toIso8601String();
                    $state['status'] = 'in_progress';
                    if ($activity['engine'] === 'word_defense') {
                        $state['battle'] = (new WordDefenseBattle)->initial($activity, hash_hmac('sha256', $sessionId.':'.$key, config('app.key')), now()->getTimestampMs() / 1000);
                    }
                    if ($activity['timed']) {
                        $state['expires_at'] = now()->addSeconds($activity['time_limit_seconds'])->toIso8601String();
                    }
                }
            } else {
                abort_unless($progress['current'] === $key && $state['started_at'], 422, 'Mở hoạt động trước khi thao tác.');
                $expired = $state['expires_at'] && now()->greaterThanOrEqualTo($state['expires_at']);
                if ($expired) {
                    $kind = 'timeout';
                }
                if ($kind === 'timeout') {
                    abort_unless($expired, 422, 'Hoạt động chưa hết thời gian.');
                    foreach ($activity['items'] as $q) {
                        if (! ($state['items'][$q['id']]['resolved'] ?? false)) {
                            $state['items'][$q['id']] = [...($state['items'][$q['id']] ?? ['tries' => 0, 'first_correct' => false]), 'resolved' => true, 'correct' => false];
                        }
                    }
                    $feedback = ['correct' => false, 'resolved' => true, 'message' => 'Đã hết thời gian. Kết quả đã làm vẫn được ghi nhận; em có thể ôn tập sau vòng học.'];
                } elseif ($kind === 'hint') {
                    abort_unless($activity['allow_hints'], 403, 'Giáo viên chưa bật gợi ý cho hoạt động này.');
                    $state['hints']++;
                    if (isset($activity['pairs'])) {
                        $pair = collect($activity['pairs'])->first(fn ($p) => ! in_array($p['id'], $state['matched'], true));
                        $cards = array_values(array_filter($this->cards($session, $activity), fn ($c) => $c['pair'] === $pair['id']));
                        $feedback = ['message' => $pair['left'].' ↔ '.$pair['right'], 'hint' => true, 'card_ids' => array_column($cards, 'id')];
                        if ($activity['engine'] === 'memory_flip') {
                            abort_if($state['resolving'] || $state['flipped'], 409, 'Hoàn thành lượt lật hiện tại trước khi xem gợi ý.');
                            $state['flipped'] = array_column($cards, 'id');
                            $state['flips'] += 2;
                            $state['resolving'] = true;
                        }
                    } else {
                        $item = collect($activity['items'])->firstWhere('id', $action['item_id'] ?? null);
                        abort_unless($item && ! empty($item['hint']) && ! ($state['items'][$item['id']]['resolved'] ?? false), 422, 'Câu này không có gợi ý khả dụng.');
                        $feedback = ['message' => $item['hint'], 'hint' => true];
                    }
                } elseif ($activity['engine'] === 'matching' && $kind === 'match') {
                    $cards = collect($this->cards($session, $activity));
                    $left = $cards->firstWhere('id', $action['left_id'] ?? null);
                    $right = $cards->firstWhere('id', $action['right_id'] ?? null);
                    abort_unless($left && $right && $left['side'] === 'left' && $right['side'] === 'right' && ! in_array($left['pair'], $state['matched'], true) && ! in_array($right['pair'], $state['matched'], true), 422, 'Chọn một thẻ chưa ghép từ mỗi bên.');
                    $ok = $left['pair'] === $right['pair'];
                    $state['actions']++;
                    if ($ok) {
                        $state['matched'][] = $left['pair'];
                    } else {
                        $state['wrong']++;
                    }
                    $this->pairMistake($session, $activity, $left['pair'], $ok);
                    $state['responses'][] = ['left' => $left['text'], 'right' => $right['text'], 'correct' => $ok];
                    $feedback = ['correct' => $ok, 'message' => $ok ? 'Chính xác! Hai thẻ đã được ghép.' : 'Hai thẻ chưa khớp. Thử một cặp khác nhé!', 'card_ids' => [$left['id'], $right['id']]];
                } elseif ($activity['engine'] === 'memory_flip' && $kind === 'flip') {
                    abort_if($state['resolving'], 409, 'Đợi hai thẻ úp lại trước khi lật tiếp.');
                    $card = collect($this->cards($session, $activity))->firstWhere('id', $action['card_id'] ?? null);
                    abort_unless($card && ! in_array($card['id'], $state['flipped'], true) && ! in_array($card['pair'], $state['matched'], true), 422, 'Chọn một thẻ đang úp.');
                    $state['flipped'][] = $card['id'];
                    $state['flips']++;
                    if (count($state['flipped']) === 2) {
                        $first = collect($this->cards($session, $activity))->firstWhere('id', $state['flipped'][0]);
                        $ok = $first['pair'] === $card['pair'];
                        $state['actions']++;
                        if ($ok) {
                            $state['matched'][] = $card['pair'];
                            $state['flipped'] = [];
                        } else {
                            $state['wrong']++;
                            $state['resolving'] = true;
                        }
                        $this->pairMistake($session, $activity, $first['pair'], $ok);
                        $state['responses'][] = ['left' => $first['text'], 'right' => $card['text'], 'correct' => $ok];
                        $feedback = ['correct' => $ok, 'message' => $ok ? 'Tìm thấy một đôi rồi! Giỏi lắm!' : 'Ghi nhớ hai thẻ này và thử tiếp nhé!'];
                    }
                } elseif ($activity['engine'] === 'memory_flip' && $kind === 'resolve') {
                    abort_unless($state['resolving'], 422, 'Không có lượt lật đang chờ.');
                    $state['flipped'] = [];
                    $state['resolving'] = false;
                } elseif (in_array($activity['engine'], ['word_builder', 'quick_challenge', 'fill_gap'], true) && in_array($kind, ['answer', 'skip'], true)) {
                    $item = collect($activity['items'])->firstWhere('id', $action['item_id'] ?? null);
                    abort_unless($item && ! ($state['items'][$item['id']]['resolved'] ?? false), 422, 'Câu hỏi không hợp lệ hoặc đã hoàn thành.');
                    $current = collect($activity['items'])->first(fn ($q) => ! ($state['items'][$q['id']]['resolved'] ?? false));
                    abort_unless($current['id'] === $item['id'], 422, 'Hoàn thành câu hiện tại trước.');
                    $answer = $action['answer'] ?? null;
                    abort_unless($answer === null || (is_string($answer) && mb_strlen($answer) <= 500) || (is_array($answer) && array_is_list($answer) && count($answer) <= 24 && count(array_filter($answer, fn ($v) => ! is_string($v) || strlen($v) > 64)) === 0), 422, 'Câu trả lời không hợp lệ.');
                    $itemState = $state['items'][$item['id']] ?? ['tries' => 0, 'first_correct' => false, 'resolved' => false, 'correct' => false];
                    if ($kind === 'skip') {
                        abort_unless($itemState['tries'] > 0, 422, 'Thử trả lời trước khi chuyển sang câu tiếp.');
                        $itemState['resolved'] = true;
                        $ok = false;
                    } else {
                        $ok = $this->content->correct($item, $answer, $activity['engine']);
                        $itemState['tries']++;
                        $state['actions']++;
                        if ($itemState['tries'] === 1) {
                            $itemState['first_correct'] = $ok;
                        }
                        $itemState['correct'] = $ok;
                        $itemState['resolved'] = $ok || $itemState['tries'] >= $activity['retry_limit'];
                        $state['responses'][] = ['item_id' => $item['id'], 'answer' => $answer, 'correct' => $ok];
                        $this->mistake($session, $activity, $item, $ok);
                    }
                    $state['items'][$item['id']] = $itemState;
                    unset($progress['drafts'][$key][$item['id']]);
                    $feedback = ['item_id' => $item['id'], 'prompt' => $item['prompt'], 'correct' => $ok, 'resolved' => $itemState['resolved'], 'can_retry' => ! $itemState['resolved'], 'message' => $ok ? 'Chính xác! Bạn làm tốt lắm!' : ($itemState['resolved'] ? 'Đã ghi nhận. Cùng ôn lại sau vòng học nhé!' : 'Gần đúng rồi! Thử lại một lần nữa nhé!'), 'explanation' => $activity['show_explanations'] ? ($item['explanation'] ?? '') : null, 'answer' => $activity['show_explanations'] && $itemState['resolved'] ? $this->solution($item, $activity['engine']) : null];
                } else {
                    abort(422, 'Thao tác không phù hợp với trò chơi này.');
                }
            }
            $state['responses'] = array_slice($state['responses'], -100);
            $complete = $activity['engine'] === 'word_defense' ? $state['battle']['status'] !== 'playing' : (isset($activity['pairs']) ? count($state['matched']) === count($activity['pairs']) : count(array_filter($state['items'], fn ($q) => $q['resolved'])) === count($activity['items']));
            if ($complete) {
                $state = $this->completeActivity($session, $definition, $activity, $state);
                foreach ($definition['activities'] as $i => $a) {
                    if ($a['id'] === $key && isset($definition['activities'][$i + 1])) {
                        $next = $definition['activities'][$i + 1]['id'];
                        if ($progress['activities'][$next]['status'] === 'locked') {
                            $progress['activities'][$next]['status'] = 'available';
                        }
                    }
                }
            }
            $progress['activities'][$key] = $state;
            $progress['events']++;
            if (count(array_filter($progress['activities'], fn ($s) => $s['status'] === 'completed')) === count($definition['activities'])) {
                $progress['round_result'] = $this->completeRound($session, $definition, $progress);
                $session->status = 'completed';
                $session->completed_at = now();
                $session->final_accuracy = $progress['round_result']['accuracy'];
                $session->final_stars = $progress['round_result']['stars'];
            }
            $session->revision++;
            $session->progress = json_encode($progress, JSON_UNESCAPED_UNICODE);
            $this->access->table('arcade_sessions')->where('id', $sessionId)->update(['progress' => $session->progress, 'revision' => $session->revision, 'status' => $session->status, 'completed_at' => $session->completed_at, 'final_accuracy' => $session->final_accuracy, 'final_stars' => $session->final_stars, 'updated_at' => now()]);
            $this->access->table('arcade_events')->insert(['session_id' => $sessionId, 'event_key' => $eventKey, 'payload_hash' => $hash, 'feedback' => $feedback === null ? null : json_encode($feedback, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);

            return $this->view($session, $feedback);
        });
    }

    private function solution(array $item, string $engine): array|string
    {
        if ($engine === 'word_builder') {
            $tokens = array_column($item['tokens'], 'text', 'id');

            return array_map(fn ($order) => implode(' ', array_map(fn ($id) => $tokens[$id], $order)), $item['accepted_orders']);
        }

        return $item['accepted_answers'];
    }

    private function mistake(object $session, array $a, array $q, bool $correct): void
    {
        if ($session->is_preview) {
            return;
        }
        $keys = ['student_id' => $session->student_id, 'version_id' => $session->version_id, 'activity_key' => $a['id'], 'item_key' => $q['id']];
        $query = $this->access->table('arcade_mistakes')->where($keys);
        if ($correct) {
            $query->update(['corrected_at' => now(), 'updated_at' => now()]);

            return;
        }
        $previous = (clone $query)->first();
        $review = ['prompt' => $q['prompt'], 'explanation' => $a['show_explanations'] ? ($q['explanation'] ?? '') : null, 'answer' => $a['show_explanations'] ? $this->solution($q, $a['engine']) : null];
        $payload = ['round_id' => $session->round_id, 'topic' => $a['topic'], 'objective' => $a['objective'], 'review' => json_encode($review, JSON_UNESCAPED_UNICODE), 'wrong_count' => ($previous->wrong_count ?? 0) + 1, 'last_wrong_at' => now(), 'corrected_at' => null, 'updated_at' => now()];
        if ($previous) {
            $query->update($payload);
        } else {
            $this->access->table('arcade_mistakes')->insert([...$keys, ...$payload, 'created_at' => now()]);
        }
    }

    private function pairMistake(object $session, array $a, string $pairId, bool $correct): void
    {
        $pair = collect($a['pairs'])->firstWhere('id', $pairId);
        $this->mistake($session, $a, ['id' => $pairId, 'prompt' => $pair['left'], 'accepted_answers' => [$pair['right']], 'explanation' => 'Ôn lại mối liên hệ giữa hai khái niệm.'], $correct);
    }

    private function reward(object $session, string $key, int $amount): int
    {
        if ($session->is_preview || $amount <= 0) {
            return 0;
        }
        $inserted = $this->access->table('arcade_rewards')->insertOrIgnore(['student_id' => $session->student_id, 'round_id' => $session->round_id, 'session_id' => $session->id, 'reward_key' => $key, 'amount' => $amount, 'created_at' => now(), 'updated_at' => now()]);

        return $inserted ? $amount : 0;
    }

    private function achievement(object $session, string $code): bool
    {
        if ($session->is_preview) {
            return false;
        }

        return $this->access->table('student_achievements')->insertOrIgnore(['student_id' => $session->student_id, 'code' => $code, 'created_at' => now(), 'updated_at' => now()]) === 1;
    }

    private function completeActivity(object $session, array $definition, array $a, array $state): array
    {
        $correct = isset($a['pairs']) ? count($state['matched']) : count(array_filter($state['items'], fn ($q) => $q['first_correct']));
        $total = isset($a['pairs']) ? count($a['pairs']) + $state['wrong'] : count($a['items']);
        if ($a['engine'] === 'word_defense') {
            $correct = $state['battle']['status'] === 'won' ? $state['battle']['correct'] : 0;
            $total = max(1, $state['battle']['correct'] + $state['battle']['wrong']);
        }
        $accuracy = $total ? round(100 * $correct / $total, 2) : 0;
        $streak = 0;
        $best = 0;
        foreach ($state['responses'] as $response) {
            $streak = $response['correct'] ? $streak + 1 : 0;
            $best = max($best, $streak);
        }
        $xp = $this->reward($session, 'activity:'.$a['id'], $this->content->activityXp($accuracy, $definition['rewards']));
        $duration = max(0, (int) Carbon::parse($state['started_at'])->diffInSeconds(now()));
        $review = [];
        foreach ($a['items'] ?? [] as $q) {
            $review[] = ['id' => $q['id'], 'prompt' => $q['prompt'], 'first_correct' => $state['items'][$q['id']]['first_correct'] ?? false, 'correct' => $state['items'][$q['id']]['correct'] ?? false, 'tries' => $state['items'][$q['id']]['tries'] ?? 0, 'answer' => $a['show_explanations'] ? $this->solution($q, $a['engine']) : null, 'explanation' => $a['show_explanations'] ? ($q['explanation'] ?? '') : null];
        }
        $result = ['activity_id' => $a['id'], 'title' => $a['title'], 'engine' => $a['engine'], 'correct_count' => $correct, 'total_count' => $total, 'accuracy' => $accuracy, 'stars' => $this->content->stars($accuracy, $definition['stars']), 'points' => $this->content->points($correct, $best), 'combo' => min(3, $best), 'xp_awarded' => $xp, 'duration_seconds' => $duration, 'action_count' => $state['actions'], 'flips' => $state['flips'], 'hint_count' => $state['hints'], 'review' => $review];
        if ($a['engine'] === 'word_defense') {
            $result['battle'] = ['status' => $state['battle']['status'], 'kills' => $state['battle']['kills'], 'health' => $state['battle']['health'], 'waves' => $state['battle']['waves'], 'seconds' => $state['battle']['time']];
            $result['combo'] = min(3, $state['battle']['best_combo']);
            foreach ($a['pairs'] as $i => $pair) {
                $learning = $state['battle']['learning'][$i] ?? ['correct' => 0, 'wrong' => 0];
                $ok = $learning['correct'] > 0 && $learning['wrong'] === 0;
                $result['review'][] = ['id' => $pair['id'], 'prompt' => $pair['right'], 'first_correct' => $ok, 'correct' => $learning['correct'] > 0, 'tries' => $learning['correct'] + $learning['wrong'], 'answer' => $a['show_explanations'] ? $pair['left'] : null, 'explanation' => null];
                if ($learning['wrong'] > 0) {
                    $this->pairMistake($session, $a, $pair['id'], false);
                    if ($learning['correct'] > 0) {
                        $this->pairMistake($session, $a, $pair['id'], true);
                    }
                }
            }
            $review = $result['review'];
        }
        if (! $session->is_preview) {
            $this->access->table('arcade_results')->insert(['session_id' => $session->id, 'round_id' => $session->round_id, 'version_id' => $session->version_id, 'student_id' => $session->student_id, 'subject_id' => $definition['subject_id'], 'activity_key' => $a['id'], 'engine' => $a['engine'], 'topic' => $a['topic'], 'objective' => $a['objective'], 'correct_count' => $correct, 'total_count' => $total, 'action_count' => $state['actions'], 'hint_count' => $state['hints'], 'duration_seconds' => $duration, 'accuracy' => $accuracy, 'stars' => $result['stars'], 'points' => $result['points'], 'xp_awarded' => $xp, 'details' => json_encode(['review' => $review, 'flips' => $state['flips'], 'responses' => $state['responses']], JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            $this->achievement($session, 'first_game_completed');
            if (in_array($a['engine'], ['matching', 'memory_flip'], true) && $state['wrong'] === 0) {
                $this->achievement($session, 'perfect_match');
            }
            $subject = $this->access->table('subjects')->find($definition['subject_id']);
            if ($subject->name === 'Toán học') {
                $this->achievement($session, 'math_explorer');
            }
            if ($subject->name === 'Tiếng Anh' && $a['engine'] === 'fill_gap' && $accuracy >= 90) {
                $this->achievement($session, 'grammar_master');
            }
            if ($subject->name === 'Tiếng Anh' && $this->access->table('arcade_results')->where('student_id', $session->student_id)->where('subject_id', $subject->id)->count() >= 3) {
                $this->achievement($session, 'vocabulary_explorer');
            }
            $days = $this->access->table('arcade_results')->where('student_id', $session->student_id)->where('created_at', '>=', now()->subDays(2)->startOfDay())->selectRaw('DATE(created_at) as day')->distinct()->pluck('day')->all();
            if (count($days) === 3) {
                $this->achievement($session, 'consistent_learner');
            }
        }

        return [...$state, 'status' => 'completed', 'result' => $result];
    }

    private function completeRound(object $session, array $definition, array $progress): array
    {
        $results = array_column($progress['activities'], 'result');
        $correct = array_sum(array_column($results, 'correct_count'));
        $total = array_sum(array_column($results, 'total_count'));
        $accuracy = $total ? round(100 * $correct / $total, 2) : 0;
        $won = count(array_filter($results, fn ($r) => isset($r['battle']) && $r['battle']['status'] !== 'won')) === 0;
        $met = $won && $accuracy > 0 && $accuracy >= $definition['completion_accuracy'];
        $bonus = $met ? $this->reward($session, 'round', $definition['rewards']['round']) : 0;
        $mastery = $won && $accuracy >= $definition['stars'][2] ? $this->reward($session, 'mastery', $definition['rewards']['mastery']) : 0;

        return ['accuracy' => $accuracy, 'correct_count' => $correct, 'total_count' => $total, 'stars' => $this->content->stars($accuracy, $definition['stars']), 'xp_awarded' => array_sum(array_column($results, 'xp_awarded')) + $bonus + $mastery, 'round_bonus' => $bonus, 'mastery_bonus' => $mastery, 'duration_seconds' => max(0, (int) Carbon::parse($session->started_at)->diffInSeconds(now())), 'met_requirement' => $met, 'required_accuracy' => $definition['completion_accuracy'], 'results' => $results];
    }

    public function saveDraft(int $sessionId, string $activityId, string $itemId, mixed $answer): array
    {
        $this->session($sessionId, true);

        return DB::transaction(function () use ($sessionId, $activityId, $itemId, $answer) {
            $session = $this->access->table('arcade_sessions')->where('id', $sessionId)->lockForUpdate()->first();
            abort_unless($session->status === 'active', 409);
            $definition = $this->definition($session);
            $a = collect($definition['activities'])->firstWhere('id', $activityId);
            $progress = json_decode($session->progress, true);
            abort_unless($a && $progress['current'] === $activityId && isset($a['items']), 422);
            $q = collect($a['items'])->first(fn ($item) => ! ($progress['activities'][$activityId]['items'][$item['id']]['resolved'] ?? false));
            abort_unless($q && $q['id'] === $itemId, 409, 'Câu này đã hoàn thành hoặc không còn là câu hiện tại.');
            abort_unless(($a['engine'] === 'word_builder' && is_array($answer) && array_is_list($answer) && count($answer) <= count($q['tokens']) && count(array_filter($answer, fn ($v) => ! is_string($v))) === 0 && count(array_unique($answer, SORT_REGULAR)) === count($answer) && ! array_diff($answer, array_column($q['tokens'], 'id'))) || ($a['engine'] !== 'word_builder' && is_string($answer) && mb_strlen($answer) <= 500), 422, 'Bản nháp không hợp lệ.');
            $progress['drafts'][$activityId][$itemId] = $answer;
            $this->access->table('arcade_sessions')->where('id', $sessionId)->update(['progress' => json_encode($progress, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);

            return ['saved_at' => now()->toIso8601String()];
        });
    }
}
