<?php

namespace App;

use App\Jobs\DeliverNotificationEmail;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class LearningAccess
{
    public function user(): User
    {
        return auth()->user();
    }

    public function table(string $table): TenantQuery
    {
        return TenantQuery::forOrganization($table, (int) $this->user()->organization_id);
    }

    public function requireRole(string ...$roles): void
    {
        abort_unless(in_array($this->user()->role, $roles, true), 403, 'Bạn không có quyền thực hiện thao tác này.');
    }

    public function totalXp(?int $studentId = null): int
    {
        $id = $studentId ?? $this->user()->id;

        return (int) $this->table('xp_transactions')->where('student_id', $id)->sum('amount')
            + (int) $this->table('arcade_rewards')->where('student_id', $id)->sum('amount');
    }

    public function studentIds(): array
    {
        $query = $this->table('users')->where('role', 'student');

        return match ($this->user()->role) {
            'admin' => $query->pluck('id')->all(),
            'teacher' => $query->whereIn('id', $this->table('teacher_students')->where('teacher_id', $this->user()->id)->select('student_id'))->pluck('id')->all(),
            'student' => [$this->user()->id],
            'parent' => $query->whereIn('id', $this->table('parent_student_links')->where('parent_id', $this->user()->id)->select('student_id'))->pluck('id')->all(),
            default => [],
        };
    }

    public function assigned(string $resource): TenantQuery
    {
        [$recipients, $column] = match ($resource) {
            'assignments' => ['assignment_students', 'assignment_id'],
            'lessons' => ['lesson_students', 'lesson_id'],
            'games' => ['game_students', 'game_id'],
        };
        $query = $this->table($resource);
        if ($this->user()->role === 'teacher') {
            return $query->where($resource.'.teacher_id', $this->user()->id);
        }
        if ($this->user()->role !== 'admin') {
            $query->whereIn($resource.'.id', $this->table($recipients)->whereIn('student_id', $this->studentIds())->select($column));
        }

        return $query;
    }

    public function resource(string $resource, int $id, bool $write = false): object
    {
        $row = $this->assigned($resource)->where($resource.'.id', $id)->first();
        abort_unless($row && (! $write || in_array($this->user()->role, ['admin', 'teacher'], true)), 404, 'Không tìm thấy nội dung.');

        return $row;
    }

    public function recipientRules(): array
    {
        return ['student_ids' => ['required', 'array', 'min:1', 'max:1000'], 'student_ids.*' => ['required', 'integer', 'distinct', $this->exists('users')->where('role', 'student')->where('active', true)]];
    }

    public function connectStudents(int $teacherId, array $students): void
    {
        foreach (array_unique($students) as $student) {
            $this->table('teacher_students')->insertOrIgnore(['teacher_id' => $teacherId, 'student_id' => $student]);
        }
    }

    public function setRecipients(string $table, string $column, int $id, array $students, int $teacherId): void
    {
        $this->table($table)->where($column, $id)->delete();
        foreach (array_unique($students) as $student) {
            $this->table($table)->insert([$column => $id, 'student_id' => $student]);
        }
        $this->connectStudents($teacherId, $students);
    }

    public function assignment(int $id, bool $write = false): object
    {
        $row = $this->resource('assignments', $id, $write);
        abort_unless($this->user()->role !== 'student' || ($row->published && ! in_array($row->lifecycle, ['archived', 'draft'], true)), 404, 'Không tìm thấy bài tập.');

        return $row;
    }

    public function exists(string $table, string $column = 'id'): mixed
    {
        return Rule::exists($table, $column)->where('organization_id', $this->user()->organization_id);
    }

    public function enforceLimit(string $resource): void
    {
        $org = DB::table('organizations')->where('id', $this->user()->organization_id)->lockForUpdate()->first();
        abort_unless($org && $org->subscription_status === 'active', 403, 'Gói dịch vụ đang tạm ngưng.');
        [$table, $limit, $role] = match ($resource) {
            'teachers' => ['users', $org->max_teachers, 'teacher'],
            default => ['users', $org->max_students, 'student'],
        };
        $query = $this->table($table)->where('role', $role)->where('active', true);
        abort_if($query->count() >= $limit, 422, 'Đã đạt giới hạn của gói dịch vụ.');
    }

    public function audit(string $action, string $entity, int $id, mixed $before = null, mixed $after = null): void
    {
        $this->table('audit_logs')->insert(['actor_id' => request()->attributes->get('identity_user', $this->user())->id, 'action' => $action, 'entity_type' => $entity, 'entity_id' => $id, 'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE), 'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function notify(array $ids, string $title, string $body, string $kind = 'info'): void
    {
        $parents = $this->table('parent_student_links')->whereIn('student_id', $ids)->pluck('parent_id')->all();
        $safeIds = $this->table('users')->whereIn('id', array_unique([...$ids, ...$parents]))->pluck('id')->all();
        foreach ($safeIds as $userId) {
            $id = $this->table('notifications')->insertGetId(['user_id' => $userId, 'title' => $title, 'body' => $body, 'kind' => $kind, 'created_at' => now(), 'updated_at' => now()]);
            if (config('eduquest.email_notifications') && $this->table('notification_preferences')->where('user_id', $userId)->where('email_enabled', true)->exists()) {
                DeliverNotificationEmail::dispatch($id)->afterCommit();
            }
        }
    }

    public function storeFile(UploadedFile $file, string $directory, array $attributes): int
    {
        $path = null;
        $disk = config('filesystems.default');
        abort_if(app()->environment('production') && config('filesystems.disks.'.$disk.'.driver') === 'local', 503, 'Cần cấu hình kho đối tượng bền vững trước khi tải tệp lên.');
        try {
            return DB::transaction(function () use ($file, $directory, $attributes, $disk, &$path) {
                $org = DB::table('organizations')->where('id', $this->user()->organization_id)->lockForUpdate()->first();
                abort_unless($org && $org->subscription_status === 'active', 403);
                abort_if($this->table('attachments')->sum('size') + $file->getSize() > $org->storage_limit_bytes, 422, 'Đã đạt giới hạn lưu trữ của gói dịch vụ.');
                $path = $file->store($this->user()->organization_id.'/'.$directory, $disk);
                abort_unless($path, 503, 'Không thể lưu tệp. Vui lòng thử lại.');

                return $this->table('attachments')->insertGetId([...$attributes, 'name' => $file->getClientOriginalName(), 'path' => $path, 'disk' => $disk, 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'created_at' => now(), 'updated_at' => now()]);
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk($disk)->delete($path);
            }
            throw $exception;
        }
    }

    public function file(int $id): object
    {
        $file = $this->table('attachments')->where('id', $id)->whereNull('archived_at')->first();
        abort_unless($file, 404);
        if ($file->assignment_id) {
            $this->assignment($file->assignment_id);
        } elseif ($file->attempt_id) {
            $attempt = $this->table('assignment_attempts')->where('id', $file->attempt_id)->first();
            abort_unless($attempt, 404);
            if ($attempt->student_id !== $this->user()->id) {
                $this->assignment($attempt->assignment_id, true);
            }
        } elseif ($file->submission_id) {
            $submission = $this->table('submissions')->where('id', $file->submission_id)->first();
            abort_unless($submission, 404);
            if ($submission->student_id !== $this->user()->id) {
                $this->assignment($submission->assignment_id, true);
            }
        } elseif ($messageFile = $this->table('message_files')->where('attachment_id', $id)->first()) {
            $me = $this->user()->id;
            abort_unless($messageFile->uploader_id === $me || ($messageFile->peer_id === $me && $messageFile->message_id), 404);
            $peer = $messageFile->uploader_id === $me ? $messageFile->peer_id : $messageFile->uploader_id;
            abort_unless(in_array($messageFile->student_id, array_column($this->conversationStudents($peer), 'id'), true), 404);
        } else {
            $shared = $this->user()->role === 'student' && $file->visibility === 'assigned' && $this->table('material_students')->where('attachment_id', $id)->where('student_id', $this->user()->id)->exists();
            abort_unless($file->resource_owner_id === $this->user()->id || $shared, 404);
        }

        if ($file->current_file_id) {
            $current = $this->table('attachments')->find($file->current_file_id);
            abort_unless($current, 404);
            foreach (['path', 'disk', 'mime', 'size'] as $key) {
                $file->$key = $current->$key;
            }
        }

        return $file;
    }

    public function conversationStudents(int $peerId): array
    {
        $peer = $this->table('users')->where('id', $peerId)->where('active', true)->first();
        if (! $peer || ! in_array($this->user()->role, ['teacher', 'admin', 'parent'], true)) {
            return [];
        }
        if ($this->user()->role === 'parent') {
            if (! in_array($peer->role, ['teacher', 'admin'], true)) {
                return [];
            }
            $linked = $this->table('parent_student_links')->where('parent_id', $this->user()->id)->pluck('student_id');
            $students = $peer->role === 'admin' ? $linked : $this->table('teacher_students')->where('teacher_id', $peerId)->whereIn('student_id', $linked)->pluck('student_id');
        } else {
            if (! in_array($peer->role, ['parent', 'family'], true)) {
                return [];
            }
            $linked = $this->table('parent_student_links')->where('parent_id', $peerId)->pluck('student_id');
            $students = array_intersect($this->studentIds(), $linked->all());
        }

        return $this->table('users')->whereIn('id', $students)->where('active', true)->where('role', 'student')->select('id', 'name')->get()->map(fn ($row) => (array) $row)->all();
    }
}
