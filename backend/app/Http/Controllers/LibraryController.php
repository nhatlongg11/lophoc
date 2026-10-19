<?php

namespace App\Http\Controllers;

use App\LearningAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LibraryController extends Controller
{
    public const CATEGORIES = ['midterm', 'final', 'weekly_revision', 'subject_revision', 'general', 'practice_exam'];

    public function __construct(private LearningAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'folder' => ['nullable', 'string', 'max:160'], 'subject_id' => ['nullable', 'integer'], 'exam_category' => ['nullable', Rule::in(self::CATEGORIES)], 'grade' => ['nullable', 'string', 'max:40']]);
        $query = $this->access->table('attachments')->whereNotNull('resource_owner_id')->whereNull('archived_at');
        if ($this->access->user()->role === 'student') {
            $query->where('visibility', 'assigned');
            $query->whereIn('id', $this->access->table('material_students')->where('student_id', $this->access->user()->id)->select('attachment_id'));
        } else {
            $this->access->requireRole('teacher', 'admin');
            $query->where('resource_owner_id', $this->access->user()->id);
        }
        $rows = $query->when(! empty($data['search']), fn ($q) => $q->where('name', 'like', '%'.$data['search'].'%'))->when(array_key_exists('folder', $data), fn ($q) => $q->where('folder', $data['folder']))->when(! empty($data['subject_id']), fn ($q) => $q->where('subject_id', $data['subject_id']))->when(! empty($data['exam_category']), fn ($q) => $q->where('exam_category', $data['exam_category']))->when(! empty($data['grade']), fn ($q) => $q->where('grade', $data['grade']))->select('id', 'name', 'mime', 'size', 'url', 'folder', 'created_at', 'description', 'subject_id', 'grade', 'semester', 'exam_category', 'visibility', 'download_allowed', 'published_at', 'practice_round_id', 'current_file_id')->orderByDesc('id')->limit(100)->get();

        foreach ($rows as $row) {
            $metadata = $row;
            if ($metadata->current_file_id) {
                $current = $this->access->table('attachments')->find($metadata->current_file_id);
                $row->mime = $current->mime;
                $row->size = $current->size;
            }
        }

        return response()->json($rows);
    }

    public function upload(Request $request): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        $data = $request->validate(['file' => ['required_without:url', 'file', 'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp,mp3,wav,ogg,m4a', 'max:51200'], 'url' => ['required_without:file', 'nullable', 'url:http,https', 'max:2048'], 'name' => ['nullable', 'string', 'max:180'], 'folder' => ['nullable', 'string', 'max:160'], 'student_ids' => ['sometimes', 'array', 'max:1000'], 'student_ids.*' => ['integer', 'distinct'], ...$this->metadataRules()]);
        foreach ($data['student_ids'] ?? [] as $studentId) {
            abort_unless($this->access->table('users')->where('id', $studentId)->where('role', 'student')->where('active', true)->exists(), 422, 'Học sinh không hợp lệ.');
        }
        if (! empty($data['practice_round_id'])) {
            abort_unless($this->access->table('arcade_rounds')->where('id', $data['practice_round_id'])->where('teacher_id', $this->access->user()->id)->exists(), 422);
        }
        if ($request->hasFile('file')) {
            $id = $this->access->storeFile($data['file'], 'resources', ['resource_owner_id' => $this->access->user()->id, 'folder' => $data['folder'] ?? '']);
        } else {
            $id = $this->access->table('attachments')->insertGetId(['resource_owner_id' => $this->access->user()->id, 'url' => $data['url'], 'name' => $data['name'] ?? $data['url'], 'folder' => $data['folder'] ?? '', 'created_at' => now(), 'updated_at' => now()]);
        }
        $metadata = array_intersect_key($data, $this->metadataRules());
        $this->access->table('attachments')->where('id', $id)->update([...$metadata, ...(! empty($data['name']) ? ['name' => $data['name']] : []), 'published_at' => ! empty($data['student_ids']) ? now() : null]);
        foreach (array_unique($data['student_ids'] ?? []) as $studentId) {
            $this->access->table('material_students')->insert(['attachment_id' => $id, 'student_id' => $studentId]);
        }
        $this->access->connectStudents($this->access->user()->id, $data['student_ids'] ?? []);
        $this->access->audit('uploaded', 'material', $id);

        return response()->json(['id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        $file = $this->access->table('attachments')->where('id', $id)->where('resource_owner_id', $this->access->user()->id)->whereNull('archived_at')->first();
        abort_unless($file, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:180'], 'folder' => ['present', 'nullable', 'string', 'max:160'], 'archive' => ['boolean'], 'student_ids' => ['present', 'array', 'max:1000'], 'student_ids.*' => ['integer', 'distinct'], ...$this->metadataRules()]);
        if (! empty($data['practice_round_id'])) {
            abort_unless($this->access->table('arcade_rounds')->where('id', $data['practice_round_id'])->where('teacher_id', $this->access->user()->id)->exists(), 422);
        }
        foreach ($data['student_ids'] as $studentId) {
            abort_unless($this->access->table('users')->where('id', $studentId)->where('role', 'student')->where('active', true)->exists(), 422, 'Học sinh không hợp lệ.');
        }
        DB::transaction(function () use ($data, $id, $file) {
            $metadata = array_intersect_key($data, $this->metadataRules());
            $updated = $this->access->table('attachments')->where('id', $id)->whereNull('archived_at')->update([...$metadata, 'name' => $data['name'], 'folder' => $data['folder'] ?? '', 'archived_at' => ($data['archive'] ?? false) ? now() : null, 'published_at' => $data['student_ids'] ? ($file->published_at ?? now()) : $file->published_at, 'updated_at' => now()]);
            abort_unless($updated, 404);
            $this->access->table('material_students')->where('attachment_id', $id)->delete();
            foreach (array_unique($data['student_ids']) as $studentId) {
                $this->access->table('material_students')->insert(['attachment_id' => $id, 'student_id' => $studentId]);
            }
            $this->access->connectStudents($this->access->user()->id, $data['student_ids']);
            $this->access->audit('updated', 'material', $id, ['name' => $file->name], $data);
        });

        return response()->json(['message' => 'Đã lưu tài liệu và quyền chia sẻ.']);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        DB::transaction(function () use ($id) {
            $file = $this->access->table('attachments')->where('id', $id)->where('resource_owner_id', $this->access->user()->id)->whereNull('archived_at')->lockForUpdate()->first();
            abort_unless($file, 404);
            $this->access->table('attachments')->where('id', $id)->update(['archived_at' => now(), 'updated_at' => now()]);
            $this->access->table('material_students')->where('attachment_id', $id)->delete();
            $this->access->audit('deleted', 'material', $id, ['name' => $file->name]);
        });

        return response()->json(['message' => 'Đã xóa tài liệu khỏi kho và ngừng chia sẻ với học sinh.']);
    }

    public function permissions(int $id): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        abort_unless($this->access->table('attachments')->where('id', $id)->where('resource_owner_id', $this->access->user()->id)->whereNull('archived_at')->exists(), 404);

        return response()->json($this->access->table('material_students')->where('attachment_id', $id)->pluck('student_id'));
    }

    public function accessUrl(int $id): JsonResponse
    {
        $file = $this->access->file($id);

        return response()->json(['url' => $file->url ?: URL::temporarySignedRoute('files.preview', now()->addMinutes(5), ['id' => $id], absolute: false)]);
    }

    private function metadataRules(): array
    {
        return ['description' => ['nullable', 'string', 'max:4000'], 'subject_id' => ['nullable', 'integer', $this->access->exists('subjects')], 'grade' => ['nullable', 'string', 'max:40'], 'semester' => ['nullable', 'string', 'max:30'], 'exam_category' => ['sometimes', Rule::in(self::CATEGORIES)], 'visibility' => ['sometimes', Rule::in(['private', 'assigned'])], 'download_allowed' => ['sometimes', 'boolean'], 'practice_round_id' => ['nullable', 'integer']];
    }

    public function replaceFile(Request $request, int $id): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        abort_unless($this->access->table('attachments')->where('id', $id)->where('resource_owner_id', $this->access->user()->id)->whereNull('archived_at')->exists(), 404);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp,mp3,wav,ogg,m4a', 'max:51200']]);
        $newFile = $this->access->storeFile($data['file'], 'resources', []);
        $this->access->table('attachments')->where('id', $id)->update(['current_file_id' => $newFile, 'url' => null, 'updated_at' => now()]);
        $this->access->audit('file_replaced', 'material', $id, null, ['file_id' => $newFile]);

        return response()->json(['message' => 'Đã thay tệp; quyền chia sẻ và các tệp cũ được giữ lại.']);
    }

    public function preview(int $id): StreamedResponse
    {
        $file = $this->access->file($id);
        abort_unless($file->path && ($file->mime === 'application/pdf' || str_starts_with($file->mime ?? '', 'image/') || str_starts_with($file->mime ?? '', 'audio/')), 415);
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404, 'Không tìm thấy tệp tài liệu trong kho lưu trữ. Vui lòng liên hệ người đăng tải.');

        return Storage::disk($file->disk)->response($file->path, $file->name, ['Content-Type' => $file->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], 'inline');
    }
}
