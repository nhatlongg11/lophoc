<?php

namespace App\Http\Controllers;

use App\LearningAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommunicationController extends Controller
{
    public function __construct(private LearningAccess $access) {}

    public function contacts(): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin', 'parent');
        $user = $this->access->user();
        if ($user->role === 'parent') {
            $ids = $this->access->table('teacher_students')->whereIn('student_id', $this->access->studentIds())->pluck('teacher_id')->concat($this->access->table('users')->where('role', 'admin')->pluck('id'));
        } else {
            $students = $this->access->studentIds();
            $ids = $this->access->table('parent_student_links')->whereIn('student_id', $students)->pluck('parent_id');
        }

        return response()->json($this->access->table('users')->whereIn('id', $ids)->where('active', true)->select('id', 'name', 'role')->orderBy('name')->get()->map(function ($contact) {
            $contact->children = $this->access->conversationStudents($contact->id);
            $contact->unread_count = $this->access->table('messages')->where('sender_id', $contact->id)->where('recipient_id', $this->access->user()->id)->whereNull('read_at')->where(fn ($q) => $q->whereIn('student_id', array_column($contact->children, 'id'))->when(count($contact->children) === 1, fn ($q) => $q->orWhereNull('student_id')))->count();

            return $contact;
        })->filter(fn ($contact) => count($contact->children) > 0)->values());
    }

    private function checkPeer(int $id): void
    {
        $contacts = $this->contacts()->getData(true);
        abort_unless(in_array($id, array_column($contacts, 'id'), true), 404, 'Không tìm thấy cuộc trò chuyện.');
    }

    public function messages(Request $request): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin', 'parent');
        $data = $request->validate(['peer_id' => ['required', 'integer'], 'before' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100'], 'student_id' => ['nullable', 'integer']]);
        $peer = (int) $data['peer_id'];
        $this->checkPeer($peer);
        $student = $this->studentContext($peer, $data['student_id'] ?? null);
        $legacy = count($this->access->conversationStudents($peer)) === 1;
        $me = $this->access->user()->id;
        $this->access->table('messages')->where('sender_id', $peer)->where('recipient_id', $me)->whereNull('read_at')->where(fn ($q) => $q->where('student_id', $student)->when($legacy, fn ($q) => $q->orWhereNull('student_id')))->update(['read_at' => now()]);
        $rows = $this->access->table('messages')->where(function ($q) use ($me, $peer) {
            $q->where(fn ($q) => $q->where('sender_id', $me)->where('recipient_id', $peer))->orWhere(fn ($q) => $q->where('sender_id', $peer)->where('recipient_id', $me));
        })->where(fn ($q) => $q->where('student_id', $student)->when($legacy, fn ($q) => $q->orWhereNull('student_id')))->when(isset($data['before']), fn ($q) => $q->where('id', '<', $data['before']))->when(! empty($data['search']), fn ($q) => $q->where('body', 'like', '%'.$data['search'].'%'))->orderByDesc('id')->limit(50)->get()->reverse()->values();

        foreach ($rows as $row) {
            $row->files = $this->access->table('message_files')->join('attachments', 'message_files.attachment_id', '=', 'attachments.id')->where('message_id', $row->id)->select('attachments.id', 'attachments.name')->get();
        }

        return response()->json($rows);
    }

    public function send(Request $request): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin', 'parent');
        $data = $request->validate(['recipient_id' => ['required', 'integer'], 'body' => ['required', 'string', 'max:5000', 'regex:/\\S/u'], 'student_id' => ['nullable', 'integer'], 'attachment_ids' => ['sometimes', 'array', 'max:5'], 'attachment_ids.*' => ['integer', 'distinct']]);
        $this->checkPeer((int) $data['recipient_id']);
        $data['student_id'] = $this->studentContext((int) $data['recipient_id'], $data['student_id'] ?? null);
        $id = DB::transaction(function () use ($data) {
            $files = $data['attachment_ids'] ?? [];
            unset($data['attachment_ids']);
            foreach ($files as $fileId) {
                abort_unless($this->access->table('message_files')->where('attachment_id', $fileId)->where('uploader_id', $this->access->user()->id)->where('peer_id', $data['recipient_id'])->where('student_id', $data['student_id'])->whereNull('message_id')->lockForUpdate()->exists(), 422, 'Tệp không thuộc cuộc trò chuyện này.');
            }
            $id = $this->access->table('messages')->insertGetId([...$data, 'sender_id' => $this->access->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->access->table('message_files')->whereIn('attachment_id', $files)->update(['message_id' => $id, 'updated_at' => now()]);
            $this->access->audit('message_sent', 'message', $id, null, ['student_id' => $data['student_id'], 'recipient_id' => $data['recipient_id']]);
            $this->access->notify([$data['recipient_id']], 'Tin nhắn mới', 'Bạn có tin nhắn mới từ '.$this->access->user()->name.'.', 'message');

            return $id;
        });

        return response()->json($this->access->table('messages')->where('id', $id)->first(), 201);
    }

    private function studentContext(int $peer, ?int $student): int
    {
        $children = array_column($this->access->conversationStudents($peer), 'id');
        if ($student === null) {
            abort_unless(count($children) === 1, 422, 'Chọn học sinh để trao đổi.');

            return $children[0];
        }
        abort_unless(in_array($student, $children, true), 404);

        return $student;
    }

    public function upload(Request $request): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin', 'parent');
        $data = $request->validate(['peer_id' => ['required', 'integer'], 'student_id' => ['required', 'integer'], 'file' => ['required', 'file', 'mimes:pdf,docx,png,jpg,jpeg,webp', 'max:10240']]);
        $this->checkPeer($data['peer_id']);
        $student = $this->studentContext($data['peer_id'], $data['student_id']);
        $id = $this->access->storeFile($data['file'], 'messages', []);
        $this->access->table('message_files')->insert(['attachment_id' => $id, 'uploader_id' => $this->access->user()->id, 'peer_id' => $data['peer_id'], 'student_id' => $student, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['id' => $id, 'name' => $data['file']->getClientOriginalName()], 201);
    }

    public function announcements(): JsonResponse
    {
        $role = $this->access->user()->role;
        $this->access->requireRole('teacher', 'admin', 'parent', 'student');
        $query = $this->access->table('announcements');
        if ($role === 'teacher') {
            $query->where('author_id', $this->access->user()->id);
        } elseif ($role !== 'admin') {
            $students = $this->access->studentIds();
            $query->where(function ($q) use ($students) {
                $q->whereRaw('1 = 0');
                foreach ($students as $student) {
                    $q->orWhereJsonContains('student_ids', $student);
                }
            });
        }

        return response()->json($query->orderByDesc('id')->limit(100)->get()->map(function ($row) {
            $row->student_ids = json_decode($row->student_ids, true) ?: [];

            return $row;
        }));
    }

    public function publish(Request $request): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        $data = $request->validate(['title' => ['required', 'string', 'max:180'], 'body' => ['required', 'string', 'max:10000'], ...$this->access->recipientRules()]);
        $id = DB::transaction(function () use ($data) {
            $id = $this->access->table('announcements')->insertGetId([...$data, 'student_ids' => json_encode(array_map('intval', $data['student_ids'])), 'author_id' => $this->access->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->access->connectStudents($this->access->user()->id, $data['student_ids']);
            $this->access->notify($data['student_ids'], 'Thông báo mới', $data['title'], 'announcement');
            $this->access->audit('published', 'announcement', $id);

            return $id;
        });

        return response()->json($this->access->table('announcements')->where('id', $id)->first(), 201);
    }
}
