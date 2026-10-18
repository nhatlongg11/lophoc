<?php

namespace App;

use Illuminate\Support\Str;

class GameGenerator
{
    public function __construct(private ArcadeContent $content) {}

    public function generate(array $questions, array $config, array $bank): array
    {
        $seed = $config['seed'] ?? (string) Str::uuid();
        foreach ($questions as $i => &$question) {
            $question['id'] ??= 'legacy_'.$i;
            $question['difficulty'] ??= $bank['difficulty'];
            $question['topic'] ??= $bank['topic'];
        }
        unset($question);
        $questions = array_values(array_filter($questions, fn ($q) => (empty($config['topic']) || $q['topic'] === $config['topic']) && (empty($config['question_ids']) || in_array($q['id'], $config['question_ids'], true))));
        $selection = $config['selection'] ?? 'manual';
        if ($selection !== 'manual') {
            $questions = $this->content->shuffled($questions, $seed);
        }
        if ($selection === 'balanced') {
            $groups = collect($questions)->groupBy('difficulty')->all();
            $questions = [];
            while (array_sum(array_map(fn ($g) => $g->count(), $groups)) > 0) {
                foreach (['easy', 'medium', 'hard'] as $difficulty) {
                    if (isset($groups[$difficulty]) && $groups[$difficulty]->isNotEmpty()) {
                        $questions[] = $groups[$difficulty]->shift();
                    }
                }
            }
        }
        $questions = array_slice($questions, 0, $config['count'] ?? 10);
        $activities = [];
        $rejected = [];
        foreach ($config['engines'] as $engine) {
            $result = $this->content->bankActivities($questions, $engine, null);
            foreach ($result['rejected'] as $reason) {
                $q = $questions[$reason['index'] - 1];
                $suggestions = match ($q['type']) {
                    'image', 'single', 'true_false' => ['quick_challenge'],
                    'matching' => ['matching', 'memory_flip', 'word_defense'],
                    'ordering' => ['word_builder'],
                    'fill_blank' => ['fill_gap'],
                    default => [],
                };
                $rejected[] = [...$reason, 'engine' => $engine, 'suggested_engines' => $suggestions];
            }
            $generated = $result['activities'];
            if (in_array($engine, ['matching', 'memory_flip'], true)) {
                $imageQuestions = array_values(array_filter($questions, fn ($q) => $q['type'] === 'image' && ! empty($q['media']) && is_string($q['answer'])));
                $labels = array_column($imageQuestions, 'answer');
                if (count($imageQuestions) >= 4 && count(array_unique($labels)) === count($labels)) {
                    $keys = array_map(fn ($q) => $q['prompt'].':'.$q['media']['id'], $imageQuestions);
                    if (count(array_unique($keys)) === count($keys)) {
                        $size = (int) ceil(count($imageQuestions) / ceil(count($imageQuestions) / 10));
                        foreach (array_chunk($imageQuestions, $size) as $chunk) {
                            $pairs = array_map(fn ($q) => ['id' => 'image_'.substr(hash('sha256', $q['id']), 0, 40), 'left' => $q['prompt'], 'right' => $q['answer'], 'left_media' => $q['media']], $chunk);
                            $generated[] = ['id' => 'images_'.$engine, 'engine' => $engine, 'title' => 'Ghép hình và tên', 'topic' => $bank['topic'] ?: 'Ôn tập', 'objective' => $bank['learning_objective'] ?: 'Nhận biết hình ảnh', 'difficulty' => 1, 'allow_hints' => true, 'show_explanations' => true, 'retry_limit' => 2, 'timed' => false, 'time_limit_seconds' => 180, 'pairs' => $pairs];
                        }
                        $imagePrompts = array_column($imageQuestions, 'prompt');
                        $rejected = array_values(array_filter($rejected, fn ($r) => $r['engine'] !== $engine || ! in_array($r['prompt'], $imagePrompts, true)));
                    }
                }
            }
            if (in_array($engine, ['quick_challenge', 'fill_gap', 'word_builder'], true) && $generated) {
                $base = $generated[0];
                $base['items'] = array_merge(...array_column($generated, 'items'));
                $generated = [$base];
            }
            foreach ($generated as $i => $activity) {
                $activity['id'] = 'generated_'.$engine.'_'.$i;
                $activity['difficulty'] = match ($bank['difficulty']) {
                    'hard' => 3, 'medium' => 2, default => 1
                };
                $activity['objective'] = $bank['learning_objective'] ?: $activity['objective'];
                $activities[] = $activity;
            }
        }
        abort_if(count($activities) > 10, 422, 'Nội dung tạo hơn 10 chặng. Chọn ít câu hoặc ít trò chơi hơn để giữ đầy đủ nội dung.');

        return ['activities' => $activities, 'compatible_count' => count($activities), 'rejected' => $rejected, 'generation' => [...$config, 'seed' => $seed, 'bank_revision' => $bank['revision'], 'selected_question_ids' => array_column($questions, 'id')], 'notice' => ! $activities ? 'Không có nội dung tương thích. Xem gợi ý trò chơi cho từng câu.' : null];
    }
}
