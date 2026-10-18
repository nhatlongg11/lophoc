<?php

namespace App;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ArcadeContent
{
    public const ENGINES = ['matching', 'word_builder', 'quick_challenge', 'memory_flip', 'fill_gap', 'word_defense'];

    public function draft(array $input): array
    {
        $this->ensure(strlen(json_encode($input)) <= 1048576, 'Một vòng học có thể chứa tối đa 1 MB nội dung văn bản.');
        foreach (['grade', 'topic', 'objectives', 'description'] as $key) {
            $input[$key] ??= '';
        }
        if (isset($input['activities']) && is_array($input['activities'])) {
            foreach ($input['activities'] as &$a) {
                if (! is_array($a)) {
                    continue;
                }
                foreach (['title', 'topic', 'objective'] as $key) {
                    $a[$key] ??= '';
                }
                if (isset($a['pairs']) && is_array($a['pairs'])) {
                    foreach ($a['pairs'] as &$pair) {
                        if (is_array($pair)) {
                            $pair['left'] ??= '';
                            $pair['right'] ??= '';
                        }
                    } unset($pair);
                }
                if (isset($a['items']) && is_array($a['items'])) {
                    foreach ($a['items'] as &$item) {
                        if (! is_array($item)) {
                            continue;
                        } $item['prompt'] ??= '';
                        if (isset($item['tokens']) && is_array($item['tokens'])) {
                            foreach ($item['tokens'] as &$token) {
                                if (is_array($token)) {
                                    $token['text'] ??= '';
                                }
                            } unset($token);
                        }
                    } unset($item);
                }
            } unset($a);
        }

        return Validator::make($input, [
            'schema_version' => ['required', 'in:arcade-v1'], 'title' => ['required', 'string', 'max:180'], 'subject_id' => ['required', 'integer', 'min:1'],
            'grade' => ['present', 'string', 'max:40'], 'topic' => ['present', 'string', 'max:100'], 'objectives' => ['present', 'string', 'max:2000'], 'description' => ['present', 'string', 'max:4000'],
            'randomize_questions' => ['sometimes', 'boolean'], 'generation' => ['sometimes', 'array'], 'linear' => ['required', 'boolean'], 'completion_accuracy' => ['required', 'numeric', 'between:0,100'], 'stars' => ['required', 'array', 'size:3'], 'stars.*' => ['integer', 'between:1,100'],
            'rewards' => ['required', 'array:activity,accuracy,round,mastery'], 'rewards.activity' => ['required', 'integer', 'between:0,100'], 'rewards.accuracy' => ['required', 'integer', 'between:0,100'], 'rewards.round' => ['required', 'integer', 'between:0,200'], 'rewards.mastery' => ['required', 'integer', 'between:0,100'],
            'activities' => ['present', 'array', 'max:10'], 'activities.*' => ['array:id,engine,title,topic,objective,difficulty,allow_hints,show_explanations,retry_limit,timed,time_limit_seconds,pairs,items,defense_stage'],
            'activities.*.id' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]{1,64}$/', 'distinct'], 'activities.*.engine' => ['required', Rule::in(self::ENGINES)],
            'activities.*.title' => ['present', 'string', 'max:180'], 'activities.*.topic' => ['present', 'string', 'max:100'], 'activities.*.objective' => ['present', 'string', 'max:255'], 'activities.*.difficulty' => ['required', 'integer', 'between:1,3'],
            'activities.*.allow_hints' => ['required', 'boolean'], 'activities.*.show_explanations' => ['required', 'boolean'], 'activities.*.retry_limit' => ['required', 'integer', 'between:1,5'], 'activities.*.timed' => ['required', 'boolean'], 'activities.*.time_limit_seconds' => ['required', 'integer', 'between:30,3600'],
            'activities.*.defense_stage' => ['sometimes', 'integer', 'between:1,5'], 'activities.*.pairs' => ['sometimes', 'array', 'max:10'], 'activities.*.pairs.*' => ['array:id,left,right,left_image,right_image,left_media,right_media'], 'activities.*.pairs.*.id' => ['required', 'string', 'max:64'], 'activities.*.pairs.*.left' => ['present', 'string', 'max:500'], 'activities.*.pairs.*.right' => ['present', 'string', 'max:500'], 'activities.*.pairs.*.left_media' => ['nullable', 'array'], 'activities.*.pairs.*.right_media' => ['nullable', 'array'], 'activities.*.items.*.media' => ['nullable', 'array'], 'activities.*.items.*.media_url' => ['nullable', 'url:https', 'max:2048'], 'activities.*.items.*.choice_media' => ['sometimes', 'array'], 'activities.*.items.*.choice_ids' => ['sometimes', 'array'], 'activities.*.pairs.*.left_image' => ['nullable', 'url:https', 'max:2048'], 'activities.*.pairs.*.right_image' => ['nullable', 'url:https', 'max:2048'],
            'activities.*.items' => ['sometimes', 'array', 'max:30'], 'activities.*.items.*' => ['array:id,prompt,explanation,hint,tokens,accepted_orders,answer_mode,choices,accepted_answers,case_sensitive,numeric,media,media_url,choice_media,choice_ids'], 'activities.*.items.*.id' => ['required', 'string', 'max:64'], 'activities.*.items.*.prompt' => ['present', 'string', 'max:2000'], 'activities.*.items.*.explanation' => ['nullable', 'string', 'max:2000'], 'activities.*.items.*.hint' => ['nullable', 'string', 'max:500'],
            'activities.*.items.*.tokens' => ['sometimes', 'array', 'max:24'], 'activities.*.items.*.tokens.*' => ['array:id,text'], 'activities.*.items.*.tokens.*.id' => ['required', 'string', 'max:64'], 'activities.*.items.*.tokens.*.text' => ['present', 'string', 'max:100'], 'activities.*.items.*.accepted_orders' => ['sometimes', 'array', 'max:10'], 'activities.*.items.*.accepted_orders.*' => ['array', 'max:24'], 'activities.*.items.*.accepted_orders.*.*' => ['string', 'max:64'],
            'activities.*.items.*.answer_mode' => ['sometimes', 'in:choice,word_bank,typed'], 'activities.*.items.*.choices' => ['sometimes', 'array', 'max:12'], 'activities.*.items.*.choices.*' => ['string', 'max:500'], 'activities.*.items.*.accepted_answers' => ['sometimes', 'array', 'max:10'], 'activities.*.items.*.accepted_answers.*' => ['string', 'max:500'], 'activities.*.items.*.case_sensitive' => ['sometimes', 'boolean'], 'activities.*.items.*.numeric' => ['sometimes', 'boolean'],
        ])->validate();
    }

    public function validate(array $input): array
    {
        $this->ensure(strlen(json_encode($input)) <= 1048576, 'Một vòng học có thể chứa tối đa 1 MB nội dung văn bản. Tách nội dung lớn thành các vòng ngắn hơn.');
        $data = Validator::make($input, [
            'schema_version' => ['required', Rule::in(['arcade-v1'])],
            'title' => ['required', 'string', 'max:180'], 'subject_id' => ['required', 'integer', 'min:1'],
            'grade' => ['required', 'string', 'max:40'], 'topic' => ['required', 'string', 'max:100'],
            'objectives' => ['required', 'string', 'max:2000'], 'description' => ['nullable', 'string', 'max:4000'],
            'randomize_questions' => ['sometimes', 'boolean'], 'generation' => ['sometimes', 'array'],
            'linear' => ['required', 'boolean'], 'completion_accuracy' => ['required', 'numeric', 'between:0,100'],
            'stars' => ['required', 'array', 'size:3'], 'stars.*' => ['required', 'integer', 'between:1,100'],
            'rewards' => ['required', 'array'], 'rewards.activity' => ['required', 'integer', 'between:0,100'],
            'rewards.accuracy' => ['required', 'integer', 'between:0,100'], 'rewards.round' => ['required', 'integer', 'between:0,200'],
            'rewards.mastery' => ['required', 'integer', 'between:0,100'],
            'activities' => ['required', 'array', 'min:1', 'max:10'],
            'activities.*.id' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]{1,64}$/', 'distinct'],
            'activities.*.engine' => ['required', Rule::in(self::ENGINES)],
            'activities.*.title' => ['required', 'string', 'max:180'], 'activities.*.topic' => ['required', 'string', 'max:100'],
            'activities.*.objective' => ['required', 'string', 'max:255'], 'activities.*.difficulty' => ['required', 'integer', 'between:1,3'],
            'activities.*.allow_hints' => ['required', 'boolean'], 'activities.*.show_explanations' => ['required', 'boolean'],
            'activities.*.retry_limit' => ['required', 'integer', 'between:1,5'],
            'activities.*.timed' => ['required', 'boolean'], 'activities.*.time_limit_seconds' => ['required', 'integer', 'between:30,3600'],
            'activities.*.defense_stage' => ['sometimes', 'integer', 'between:1,5'], 'activities.*.pairs' => ['sometimes', 'array', 'max:10'], 'activities.*.items' => ['sometimes', 'array', 'max:30'],
        ])->validate();
        $this->ensure(array_is_list($data['activities']) && array_is_list($data['stars']) && $data['stars'][0] < $data['stars'][1] && $data['stars'][1] < $data['stars'][2], 'Ngưỡng sao phải tăng dần và nội dung phải là danh sách.');
        $activities = [];
        foreach ($data['activities'] as $activity) {
            $a = array_intersect_key($activity, array_flip(['id', 'engine', 'title', 'topic', 'objective', 'difficulty', 'allow_hints', 'show_explanations', 'retry_limit', 'timed', 'time_limit_seconds', 'defense_stage']));
            $this->ensure(! $a['timed'] || $a['engine'] === 'quick_challenge', 'Chỉ Thử thách nhanh sử dụng đồng hồ. Các trò còn lại ưu tiên luyện tập thoải mái.');
            if (in_array($a['engine'], ['matching', 'memory_flip', 'word_defense'], true)) {
                $pairs = Validator::make($activity, ['pairs' => ['required', 'array', 'min:4', 'max:10'], 'pairs.*.id' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]{1,64}$/', 'distinct'], 'pairs.*.left' => ['required', 'string', 'max:500'], 'pairs.*.right' => ['required', 'string', 'max:500'], 'pairs.*.left_media' => ['nullable', 'array'], 'pairs.*.right_media' => ['nullable', 'array'], 'pairs.*.left_image' => ['nullable', 'url:https', 'max:2048'], 'pairs.*.right_image' => ['nullable', 'url:https', 'max:2048']])->validate()['pairs'];
                $this->ensure(array_is_list($pairs), 'Các cặp ghép phải là danh sách.');
                foreach (['left', 'right'] as $side) {
                    $labels = array_map(fn ($pair) => $pair[$side].':'.($pair[$side.'_media']['id'] ?? $pair[$side.'_image'] ?? ''), $pairs);
                    $this->ensure(count(array_unique($labels)) === count($pairs), 'Các nhãn hoặc hình ảnh ở mỗi phía phải phân biệt được. Bổ sung ngữ cảnh cho thẻ trùng nội dung.');
                }
                if ($a['engine'] === 'word_defense') {
                    $this->ensure(count(array_filter($pairs, fn ($pair) => mb_strlen($pair['left']) > 80 || mb_strlen($pair['right']) > 80)) === 0, 'Word Defense dùng nhãn tối đa 80 ký tự để đọc rõ trên điện thoại.');
                }
                $a['pairs'] = array_map(fn ($p) => array_intersect_key($p, array_flip(['id', 'left', 'right', 'left_image', 'right_image', 'left_media', 'right_media'])), $pairs);
            } else {
                $items = Validator::make($activity, ['items' => ['required', 'array', 'min:1', 'max:30'], 'items.*.id' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]{1,64}$/', 'distinct'], 'items.*.prompt' => ['required', 'string', 'max:2000'], 'items.*.explanation' => ['nullable', 'string', 'max:2000'], 'items.*.hint' => ['nullable', 'string', 'max:500']])->validate()['items'];
                $this->ensure(array_is_list($items), 'Câu hỏi phải là danh sách.');
                $a['items'] = [];
                foreach ($items as $i => $item) {
                    $source = $activity['items'][$i];
                    $q = array_intersect_key($item, array_flip(['id', 'prompt', 'explanation', 'hint']));
                    $q['media'] = $source['media'] ?? null;
                    $q['media_url'] = $source['media_url'] ?? null;
                    $q['choice_media'] = $source['choice_media'] ?? [];
                    $q['choice_ids'] = $source['choice_ids'] ?? [];
                    if ($a['engine'] === 'word_builder') {
                        $v = Validator::make($source, ['tokens' => ['required', 'array', 'min:2', 'max:24'], 'tokens.*.id' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]{1,64}$/', 'distinct'], 'tokens.*.text' => ['required', 'string', 'max:100'], 'accepted_orders' => ['required', 'array', 'min:1', 'max:10'], 'accepted_orders.*' => ['required', 'array', 'min:2', 'max:24'], 'accepted_orders.*.*' => ['required', 'string', 'max:64']])->validate();
                        $this->ensure(array_is_list($v['tokens']) && array_is_list($v['accepted_orders']), 'Từ và thứ tự được chấp nhận phải là danh sách.');
                        $ids = array_column($v['tokens'], 'id');
                        foreach ($v['accepted_orders'] as $order) {
                            $this->ensure(array_is_list($order) && count($order) === count($ids) && count(array_unique($order, SORT_REGULAR)) === count($ids) && ! array_diff($order, $ids), 'Mỗi thứ tự đúng phải dùng đầy đủ mỗi mã từ đúng một lần.');
                        }
                        $q['tokens'] = array_map(fn ($t) => ['id' => $t['id'], 'text' => $t['text']], $v['tokens']);
                        $q['accepted_orders'] = $v['accepted_orders'];
                    } else {
                        $v = Validator::make($source, ['answer_mode' => ['required', Rule::in($a['engine'] === 'fill_gap' ? ['choice', 'word_bank', 'typed'] : ['choice', 'typed'])], 'case_sensitive' => ['required', 'boolean'], 'numeric' => ['required', 'boolean'], 'accepted_answers' => ['required', 'array', 'min:1', 'max:10'], 'accepted_answers.*' => ['required', 'string', 'max:500'], 'choices' => ['present', 'array', 'max:12'], 'choices.*' => ['required', 'string', 'max:500', 'distinct']])->validate();
                        $this->ensure(array_is_list($v['choices']) && array_is_list($v['accepted_answers']), 'Lựa chọn và đáp án phải là danh sách.');
                        if ($v['answer_mode'] !== 'typed') {
                            $this->ensure(count($v['choices']) >= 2 && ! array_diff($v['accepted_answers'], $v['choices']), 'Cần ít nhất hai lựa chọn và đáp án đúng thuộc các lựa chọn.');
                        }
                        if ($a['engine'] === 'fill_gap') {
                            $this->ensure(substr_count($q['prompt'], '___') === 1, 'Mỗi câu Điền chỗ trống cần đúng một vị trí ___; tách nhiều chỗ trống thành các câu riêng.');
                        }
                        foreach ($v['accepted_answers'] as $answer) {
                            $this->ensure(! $v['numeric'] || $this->number($answer) !== null, 'Đáp án số phải là số thập phân hữu hạn, dùng dấu chấm và không có dấu phân cách hàng nghìn.');
                        }
                        $q = [...$q, ...$v];
                    }
                    $a['items'][] = $q;
                }
            }
            $activities[] = $a;
        }

        return [...array_intersect_key($data, array_flip(['schema_version', 'title', 'subject_id', 'grade', 'topic', 'objectives', 'description', 'randomize_questions', 'generation', 'linear', 'completion_accuracy', 'stars', 'rewards'])), 'activities' => $activities];
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['content' => $message]);
        }
    }

    public function normalize(string $answer, bool $caseSensitive = false): string
    {
        $answer = trim(preg_replace('/\s+/u', ' ', \Normalizer::normalize($answer, \Normalizer::FORM_C)));

        return $caseSensitive ? $answer : mb_strtolower($answer);
    }

    public function number(string $answer): ?string
    {
        $answer = trim($answer);
        if (! preg_match('/^([+-]?)(\d{1,12})(?:\.(\d{1,12}))?$/D', $answer, $m)) {
            return null;
        }
        $integer = ltrim($m[2], '0') ?: '0';
        $fraction = rtrim($m[3] ?? '', '0');

        return ($m[1] === '-' && ($integer !== '0' || $fraction !== '') ? '-' : '').$integer.($fraction !== '' ? '.'.$fraction : '');
    }

    public function correct(array $item, mixed $answer, string $engine): bool
    {
        if (is_string($answer) && ! empty($item['choice_ids'])) {
            $label = array_search($answer, $item['choice_ids'], true);
            if ($label !== false) {
                $answer = (string) $label;
            }
        }
        if ($engine === 'word_builder') {
            if (! is_array($answer) || ! array_is_list($answer) || count(array_filter($answer, fn ($id) => ! is_string($id))) || count(array_unique($answer)) !== count($item['tokens']) || count($answer) !== count($item['tokens'])) {
                return false;
            }
            $tokens = array_column($item['tokens'], 'text', 'id');
            if (array_diff($answer, array_keys($tokens))) {
                return false;
            }
            $actual = array_map(fn ($id) => \Normalizer::normalize($tokens[$id], \Normalizer::FORM_C), $answer);
            foreach ($item['accepted_orders'] as $order) {
                if ($actual === array_map(fn ($id) => \Normalizer::normalize($tokens[$id], \Normalizer::FORM_C), $order)) {
                    return true;
                }
            }

            return false;
        }
        if (! is_string($answer)) {
            return false;
        }
        foreach ($item['accepted_answers'] as $expected) {
            if ($item['numeric'] ? $this->number($answer) !== null && $this->number($answer) === $this->number($expected) : $this->normalize($answer, $item['case_sensitive']) === $this->normalize($expected, $item['case_sensitive'])) {
                return true;
            }
        }

        return false;
    }

    public function stars(float $accuracy, array $thresholds): int
    {
        return $accuracy >= $thresholds[2] ? 3 : ($accuracy >= $thresholds[1] ? 2 : ($accuracy >= $thresholds[0] ? 1 : 0));
    }

    public function points(int $correct, int $bestStreak): int
    {
        return $correct > 0 ? $correct * 100 + 25 * min(3, max(0, $bestStreak)) : 0;
    }

    public function activityXp(float $accuracy, array $rewards): int
    {
        return $accuracy > 0 ? $rewards['activity'] + (int) floor($rewards['accuracy'] * $accuracy / 100) : 0;
    }

    public function shuffled(array $items, string $seed): array
    {
        usort($items, fn ($a, $b) => strcmp(hash_hmac('sha256', json_encode($a), $seed), hash_hmac('sha256', json_encode($b), $seed)));

        return $items;
    }

    public function cardId(int $sessionId, string $activity, string $pair, string $side): string
    {
        return substr(hash_hmac('sha256', $sessionId.':'.$activity.':'.$pair.':'.$side, config('app.key')), 0, 24);
    }

    public function bankActivities(array $questions, string $engine, ?int $limit = 10): array
    {
        $compatible = [];
        $rejected = [];
        foreach ($questions as $index => $q) {
            $id = isset($q['id']) ? 'bank_'.substr(hash('sha256', $q['id']), 0, 40) : 'bank_'.$index;
            $type = $q['type'] ?? '';
            if ($engine === 'word_defense') {
                $answers = $q['answer'] ?? null;
                $valid = $type === 'matching' && empty($q['media']) && empty($q['choice_media']) && is_array($answers) && count($answers) >= 4 && count($answers) <= 10;
                if ($valid) {
                    foreach ($answers as $word => $meaning) {
                        $valid = $valid && is_string($word) && is_string($meaning) && trim($word) !== '' && trim($meaning) !== '' && mb_strlen($word) <= 80 && mb_strlen($meaning) <= 80;
                    }
                    $valid = $valid && count(array_unique(array_values($answers), SORT_REGULAR)) === count($answers);
                }
                if (! $valid) {
                    $rejected[] = ['index' => $index + 1, 'prompt' => $q['prompt'] ?? '', 'reason' => 'Word Defense cần 4–10 cặp từ và nghĩa đầy đủ, phân biệt được, tối đa 80 ký tự mỗi nhãn.'];

                    continue;
                }
            }
            $item = ['id' => $id, 'prompt' => $q['prompt'] ?? '', 'explanation' => $q['explanation'] ?? '', 'hint' => '', 'media_url' => ($q['type'] ?? '') === 'image' ? ($q['media_url'] ?? null) : null, 'media' => $q['media'] ?? null, 'choice_media' => $q['choice_media'] ?? []];
            $activity = ['id' => 'import_'.$index, 'engine' => $engine, 'title' => mb_substr($q['prompt'] ?? 'Hoạt động nhập', 0, 180), 'topic' => $q['topic'] ?? 'Ôn tập', 'objective' => 'Luyện tập nội dung từ ngân hàng câu hỏi', 'difficulty' => 1, 'allow_hints' => true, 'show_explanations' => true, 'retry_limit' => 2, 'timed' => false, 'time_limit_seconds' => 180];
            if (in_array($engine, ['matching', 'memory_flip', 'word_defense'], true) && $type === 'matching' && count($q['options']['left'] ?? []) >= 4 && count($q['answer'] ?? []) <= 10) {
                $activity['pairs'] = [];
                foreach ($q['answer'] as $left => $right) {
                    $activity['pairs'][] = ['id' => 'p'.count($activity['pairs']), 'left' => $left, 'right' => $right];
                }
            } elseif ($engine === 'word_builder' && $type === 'ordering') {
                $activity['items'] = [[...$item, 'tokens' => array_map(fn ($text, $i) => ['id' => 't'.$i, 'text' => $text], $q['options'], array_keys($q['options'])), 'accepted_orders' => [array_map(fn ($text) => 't'.array_search($text, $q['options'], true), $q['answer'])]]];
            } elseif (($engine === 'quick_challenge' && in_array($type, ['single', 'true_false', 'image'], true)) || ($engine === 'fill_gap' && $type === 'fill_blank' && substr_count($q['prompt'], '___') === 1)) {
                $answers = is_array($q['answer']) ? $q['answer'] : [$q['answer']];
                $choices = array_is_list($q['options'] ?? []) ? $q['options'] : [];
                $activity['items'] = [[...$item, 'answer_mode' => count($choices) >= 2 ? ($engine === 'fill_gap' ? 'word_bank' : 'choice') : 'typed', 'choices' => $choices, 'accepted_answers' => $answers, 'case_sensitive' => false, 'numeric' => false]];
            } else {
                $rejected[] = ['index' => $index + 1, 'prompt' => $q['prompt'] ?? '', 'reason' => 'Không có cấu trúc phù hợp: ghép cặp cần 4–10 cặp; xếp từ cần thứ tự đúng; điền từ cần ___ và đáp án; thử thách cần câu chọn đáp án. Không tự tạo nội dung còn thiếu.'];

                continue;
            }
            $compatible[] = $activity;
        }

        return ['activities' => $limit === null ? $compatible : array_slice($compatible, 0, $limit), 'compatible_count' => count($compatible), 'rejected' => $rejected, 'notice' => $limit !== null && count($compatible) > $limit ? 'Chỉ nhập 10 hoạt động đầu tiên vào một vòng. Các nội dung còn lại có thể tạo vòng tiếp theo.' : null];
    }
}
