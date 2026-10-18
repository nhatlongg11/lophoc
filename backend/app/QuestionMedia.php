<?php

namespace App;

use Illuminate\Support\Facades\Validator;

class QuestionMedia
{
    public function __construct(private LearningAccess $access) {}

    public function reference(mixed $media): ?array
    {
        if ($media === null) {
            return null;
        }
        abort_unless(is_array($media), 422, 'Tham chiếu hình ảnh không hợp lệ.');
        $data = Validator::make($media, ['id' => ['required', 'integer'], 'alt' => ['required', 'string', 'max:500'], 'caption' => ['nullable', 'string', 'max:1000']])->validate();
        abort_unless($this->access->table('question_media')->where('id', $data['id'])->where('owner_id', $this->access->user()->id)->exists(), 422, 'Hình ảnh không thuộc tài khoản của bạn.');

        return $data;
    }

    public function questions(array $questions): array
    {
        return array_map(function (array $question): array {
            $question['media'] = $this->reference($question['media'] ?? null);
            $choices = $question['choice_media'] ?? [];
            abort_unless(is_array($choices) && count($choices) <= 100, 422);
            $question['choice_media'] = [];
            foreach ($choices as $label => $image) {
                abort_unless(in_array((string) $label, $question['options'] ?? [], true), 422, 'Ảnh lựa chọn phải gắn với một đáp án hiện có.');
                $question['choice_media'][$label] = $this->reference($image);
            }

            return $question;
        }, $questions);
    }

    public function content(array $content): array
    {
        foreach ($content['activities'] as &$activity) {
            foreach ($activity['items'] ?? [] as $i => $item) {
                $activity['items'][$i]['choice_ids'] = [];
                foreach ($item['choices'] ?? [] as $label) {
                    $activity['items'][$i]['choice_ids'][$label] = 'choice_'.substr(hash('sha256', $item['id'].':'.$label), 0, 24);
                }
                $activity['items'][$i]['media'] = $this->reference($item['media'] ?? null);
                $activity['items'][$i]['choice_media'] = [];
                foreach ($item['choice_media'] ?? [] as $label => $image) {
                    abort_unless(in_array((string) $label, $item['choices'] ?? [], true), 422);
                    $activity['items'][$i]['choice_media'][$label] = $this->reference($image);
                }
            }
            foreach ($activity['pairs'] ?? [] as $i => $pair) {
                foreach (['left', 'right'] as $side) {
                    $activity['pairs'][$i][$side.'_media'] = $this->reference($pair[$side.'_media'] ?? null);
                    abort_if($activity['engine'] === 'word_defense' && (! empty($pair[$side.'_media']) || ! empty($pair[$side.'_image'])), 422, 'Word Defense chỉ dùng cặp từ và nghĩa; chọn Ghép đôi hoặc Lật thẻ cho hình ảnh.');
                }
            }
        }
        unset($activity);

        return $content;
    }

    public function link(array $content, string $type, int $id): void
    {
        $walk = function (array $value) use (&$walk, $type, $id): void {
            foreach ($value as $key => $item) {
                if (! is_array($item)) {
                    continue;
                }
                if (in_array($key, ['media', 'left_media', 'right_media'], true) && isset($item['id'])) {
                    $this->access->table('question_media_links')->insertOrIgnore(['media_id' => $item['id'], 'content_type' => $type, 'content_id' => $id]);
                } elseif ($key === 'choice_media') {
                    foreach ($item as $image) {
                        if ($image) {
                            $this->access->table('question_media_links')->insertOrIgnore(['media_id' => $image['id'], 'content_type' => $type, 'content_id' => $id]);
                        }
                    }
                } else {
                    $walk($item);
                }
            }
        };
        $walk($content);
    }

    public function readable(int $id): object
    {
        $media = $this->access->table('question_media')->find($id);
        abort_unless($media, 404);
        if ($media->owner_id === $this->access->user()->id) {
            return $media;
        }
        abort_unless($this->access->user()->role === 'student', 404);
        foreach ($this->access->table('question_media_links')->where('media_id', $id)->get() as $link) {
            if ($link->content_type === 'assignment') {
                $assigned = $this->access->table('assignment_classes')->where('assignment_id', $link->content_id)->whereIn('class_id', $this->access->classIds())->exists();
                if ($assigned && $this->access->table('assignments')->where('id', $link->content_id)->where('published', true)->whereNotIn('lifecycle', ['draft', 'archived'])->exists()) {
                    return $media;
                }
            } elseif ($link->content_type === 'arcade_version' && $this->access->table('arcade_sessions')->where('version_id', $link->content_id)->where('student_id', $this->access->user()->id)->exists()) {
                return $media;
            }
        }
        abort(404);
    }
}
