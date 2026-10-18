<?php

namespace App;

use Illuminate\Validation\ValidationException;

class QuestionEngine
{
    public const TYPES = ['single', 'multiple', 'true_false', 'fill_blank', 'short', 'essay', 'matching', 'ordering', 'image', 'audio', 'file', 'word_builder'];

    public function validateDefinition(array $q): void
    {
        $type = $q['type'] ?? $q['kind'] ?? 'single';
        $options = $q['options'];
        $answer = $q['answer'];
        $strings = fn ($items): bool => is_array($items) && count($items) > 0 && count(array_filter($items, fn ($v) => ! is_string($v) || trim($v) === '')) === 0;
        $valid = match ($type) {
            'single', 'true_false', 'image', 'audio' => $strings($options) && count($options) >= 2 && in_array($answer, $options, true),
            'multiple' => $strings($options) && count($options) >= 2 && $strings($answer) && count(array_diff($answer, $options)) === 0,
            'ordering' => $strings($options) && $strings($answer) && count($options) >= 2 && count(array_unique($options)) === count($options) && count($answer) === count($options) && count(array_diff($answer, $options)) === 0 && count(array_unique($answer)) === count($answer),
            'matching' => isset($options['left'], $options['right']) && $strings($options['left']) && $strings($options['right']) && count($options['left']) >= 2 && $strings($answer) && count($answer) === count($options['left']) && count(array_diff(array_keys($answer), $options['left'])) === 0 && count(array_diff(array_values($answer), $options['right'])) === 0,
            'fill_blank' => (is_string($answer) && trim($answer) !== '') || $strings($answer),
            'word_builder' => is_string($answer) && trim($answer) !== '' && $strings($options) && $this->sameLetters(implode('', $options), $answer),
            'short', 'essay', 'file' => true,
            default => false,
        };
        if (in_array($type, ['audio', 'image'], true)) {
            $valid = $valid && (! empty($q['media_url']) || ($type === 'image' && ! empty($q['media'])));
        }
        if (! $valid) {
            throw ValidationException::withMessages(['questions' => 'Câu hỏi, lựa chọn hoặc đáp án chưa hợp lệ. Kiểm tra đủ lựa chọn, cặp ghép và nội dung nghe / hình ảnh.']);
        }
    }

    private function sameLetters(string $first, string $second): bool
    {
        $a = preg_split('//u', $this->normalize($first), -1, PREG_SPLIT_NO_EMPTY);
        $b = preg_split('//u', $this->normalize($second), -1, PREG_SPLIT_NO_EMPTY);
        sort($a);
        sort($b);

        return $a === $b;
    }

    public function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->normalize($item), $value);
        }

        return is_string($value) ? mb_strtolower(trim(preg_replace('/\s+/u', ' ', \Normalizer::normalize($value, \Normalizer::FORM_C)))) : $value;
    }

    public function evaluate(array $question, mixed $actual): ?bool
    {
        if (in_array($question['type'] ?? 'single', ['essay', 'short', 'file'], true)) {
            return null;
        }
        $expected = $this->normalize($question['answer']);
        $answer = $this->normalize($actual);
        if (in_array($question['type'] ?? 'single', ['fill_blank', 'word_builder'], true) && is_array($expected)) {
            return in_array($answer, $expected, true);
        }
        if (($question['type'] ?? '') === 'multiple') {
            if (! is_array($answer) || ! is_array($expected) || count(array_filter($answer, fn ($v) => ! is_string($v))) > 0) {
                return false;
            }
            $expected = array_values(array_unique($expected));
            $answer = array_values(array_unique($answer));
            sort($expected);
            sort($answer);
        }
        if (($question['type'] ?? '') === 'matching') {
            if (! is_array($answer) || ! is_array($expected)) {
                return false;
            }
            ksort($expected);
            ksort($answer);
        }

        return $expected === $answer;
    }

    public function grade(array $questions, array $answers, float $maxScore): array
    {
        $earned = 0;
        $possible = 0;
        $manual = false;
        $grading = [];
        foreach ($questions as $question) {
            $points = (float) ($question['points'] ?? 1);
            $possible += $points;
            $correct = $this->evaluate($question, $answers[$question['id']] ?? null);
            $manual = $manual || $correct === null;
            $awarded = $correct === true ? $points : 0;
            $earned += $awarded;
            $grading[$question['id']] = ['correct' => $correct, 'points' => $awarded, 'max_points' => $points];
        }

        return ['score' => $possible ? round($earned / $possible * $maxScore, 2) : null, 'status' => $manual || ! $questions ? 'submitted' : 'graded', 'grading' => $grading];
    }

    public function gamePoints(int $correct, int $total, int $remaining, int $bestStreak): int
    {
        return $total > 0 && $correct > 0 ? $correct * 100 + (int) round(200 * $correct / $total) + min(100, max(0, $remaining)) + min(100, max(0, $bestStreak) * 10) : 0;
    }
}
