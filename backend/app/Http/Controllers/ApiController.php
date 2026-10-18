<?php

namespace App\Http\Controllers;

use App\LearningAccess;
use App\Models\User;
use App\QuestionEngine;
use App\TenantQuery;
use App\Timetable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApiController extends Controller
{
    public function __construct(private LearningAccess $access, private QuestionEngine $questionEngine, private Timetable $timetable) {}

    private function table(string $table): TenantQuery
    {
        return $this->access->table($table);
    }

    private function user(): User
    {
        return auth()->user();
    }

    private function requireRole(string ...$roles): void
    {
        abort_unless(in_array($this->user()->role, $roles, true), 403, 'Bạn không có quyền thực hiện thao tác này.');
    }

    private function auditChange(string $action, string $entity, int $id, mixed $before, mixed $after): void
    {
        $this->access->audit($action, $entity, $id, $before, $after);
    }

    private function notifyUsers(array $userIds, string $title, string $body, string $kind = 'info', ?string $url = null): void
    {
        $this->access->notify($userIds, $title, $body, $kind);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $key = 'login-failure:'.mb_strtolower($data['email']).'|'.$request->ip();
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Đăng nhập sai quá nhiều lần. Vui lòng thử lại sau 15 phút.');
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! $user->active || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['email' => 'Email hoặc mật khẩu không đúng.']);
        }
        RateLimiter::clear($key);
        abort_unless(DB::table('organizations')->where('id', $user->organization_id)->where('subscription_status', '!=', 'suspended')->exists(), 403, 'Tổ chức đang tạm ngưng hoạt động.');
        $token = Str::random(80);
        DB::table('api_tokens')->insert(['organization_id' => $user->organization_id, 'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);

        $request->session()->regenerate();
        $request->session()->put('eduquest_token', $token);
        DB::table('audit_logs')->insert(['organization_id' => $user->organization_id, 'actor_id' => $user->id, 'action' => 'login', 'entity_type' => 'user', 'entity_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $safeUser = clone $user;
        if (in_array($safeUser->role, ['parent', 'family'], true)) {
            $safeUser->role = 'family';
        }

        return response()->json(['user' => $safeUser]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->table('api_tokens')->where('id', $request->attributes->get('api_token_id'))->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Đã đăng xuất.']);
    }

    public function me(): JsonResponse
    {
        $identity = request()->attributes->get('identity_user');
        if (in_array($identity->role, ['family', 'parent'], true)) {
            $this->user()->setAttribute('is_family_session', true);
        }

        return response()->json($this->user());
    }

    public function dashboard(): JsonResponse
    {
        $assignments = $this->access->assigned('assignments')->where('published', true)->whereNotIn('lifecycle', ['draft', 'archived']);
        $assignmentIds = (clone $assignments)->pluck('assignments.id');
        $completed = $this->table('submissions')->join('assignment_students', function (JoinClause $join): void {
            $join->on('assignment_students.assignment_id', '=', 'submissions.assignment_id')->on('assignment_students.student_id', '=', 'submissions.student_id');
        })->whereIn('submissions.assignment_id', $assignmentIds)->whereIn('submissions.status', ['submitted', 'graded'])->whereNotNull('submissions.submitted_at');
        if (in_array($this->user()->role, ['student', 'parent'], true)) {
            $completed->whereIn('submissions.student_id', $this->access->studentIds());
        }
        $completedCount = $completed->distinct()->count('submissions.id');
        $this->timetable->materialize(now()->startOfDay(), now()->addDays(7)->endOfDay());
        $upcoming = $this->access->assigned('lessons')->where('starts_at', '>=', now()->startOfDay())->where('starts_at', '<=', now()->addDays(7))->where('status', '!=', 'cancelled')->orderBy('starts_at')->limit(6)->get();
        $due = (clone $assignments)->whereBetween('due_at', [now(), now()->addDays(7)])->orderBy('due_at')->limit(6)->get();

        return response()->json(['students' => count($this->access->studentIds()), 'assignments' => $assignmentIds->count(), 'pending_assignments' => max(0, $assignmentIds->count() - $completedCount), 'submissions' => $completedCount, 'notifications' => $this->table('notifications')->where('user_id', $this->user()->id)->whereNull('read_at')->count(), 'upcoming_lessons' => $upcoming, 'due_assignments' => $due]);
    }

    public function users(Request $request): JsonResponse
    {
        $this->requireRole('admin', 'teacher');
        $query = User::query()->where('users.organization_id', $this->user()->organization_id)->select('id', 'name', 'email', 'role', 'phone', 'active')->orderBy('name');
        if ($request->filled('role')) {
            $query->where('role', $request->string('role'));
        }
        if ($request->boolean('linked')) {
            $query->whereIn('id', $this->access->studentIds());
        }

        return response()->json($query->get());
    }

    public function saveUser(Request $request, ?int $id = null): JsonResponse
    {
        return DB::transaction(function () use ($request, $id): JsonResponse {
            $this->requireRole('admin');
            DB::table('organizations')->where('id', $this->user()->organization_id)->lockForUpdate()->first();
            if ($id) {
                User::where('organization_id', $this->user()->organization_id)->findOrFail($id);
            }
            $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email', Rule::unique('users')->ignore($id)], 'role' => ['required', Rule::in(['admin', 'teacher', 'student', 'family', 'parent'])], 'phone' => ['nullable', 'string', 'max:30'], 'active' => ['boolean'], 'password' => [$id ? 'nullable' : 'required', 'string', 'min:8']]);
            if ($id) {
                $user = User::where('organization_id', $this->user()->organization_id)->findOrFail($id);
                if ($user->id === $this->user()->id && isset($data['active']) && ! $data['active']) {
                    throw ValidationException::withMessages(['active' => 'Bạn không thể khóa tài khoản của mình.']);
                }
                $before = $user->only(['name', 'email', 'role', 'phone', 'active']);
                abort_if($user->id === $this->user()->id && $data['role'] !== $user->role, 422, 'Bạn không thể tự thay đổi vai trò quản trị của mình.');
                if (($data['active'] ?? $user->active) && (! $user->active || $data['role'] !== $user->role) && in_array($data['role'], ['student', 'teacher'], true)) {
                    $this->access->enforceLimit($data['role'] === 'teacher' ? 'teachers' : 'students');
                }
                if (isset($data['active']) && ! $data['active']) {
                    $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
                    $this->auditChange('account_suspended', 'user', $id, null, $reason);
                }
                $revoke = ! empty($data['password']) || ! ($data['active'] ?? $user->active) || $data['role'] !== $user->role;
                if (empty($data['password'])) {
                    unset($data['password']);
                }
                $user->update($data);
                if ($revoke) {
                    $this->table('api_tokens')->where('user_id', $user->id)->delete();
                }
                $this->auditChange('updated', 'user', $id, $before, $user->only(['name', 'email', 'role', 'phone', 'active']));
            } else {
                abort_if(in_array($data['role'], ['family', 'parent'], true), 422, 'Tài khoản gia đình cần được tạo qua lời mời để thiết lập PIN riêng.');
                $user = DB::transaction(function () use ($data) {
                    if (in_array($data['role'], ['student', 'teacher'], true)) {
                        $this->access->enforceLimit($data['role'] === 'teacher' ? 'teachers' : 'students');
                    }

                    return User::create([...$data, 'organization_id' => $this->user()->organization_id]);
                });
                $this->auditChange('created', 'user', $user->id, null, $user->only(['name', 'email', 'role']));
            }

            return response()->json($user, $id ? 200 : 201);
        });
    }

    public function subjects(): JsonResponse
    {
        return response()->json($this->table('subjects')->orderBy('name')->get());
    }

    public function saveSubject(Request $request): JsonResponse
    {
        $this->requireRole('admin');
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('subjects', 'name')->where('organization_id', $this->user()->organization_id)], 'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        $id = $this->table('subjects')->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
        $this->auditChange('created', 'subject', $id, null, $data);

        return response()->json($this->table('subjects')->find($id), 201);
    }

    public function schoolYears(): JsonResponse
    {
        return response()->json($this->table('school_years')->orderByDesc('starts_on')->get());
    }

    public function saveSchoolYear(Request $request): JsonResponse
    {
        $this->requireRole('admin');
        $data = $request->validate(['name' => ['required', 'string', Rule::unique('school_years', 'name')->where('organization_id', $this->user()->organization_id)], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after:starts_on'], 'active' => ['boolean']]);
        $id = DB::transaction(function () use ($data) {
            if ($data['active'] ?? false) {
                $this->table('school_years')->update(['active' => false]);
            }

            return $this->table('school_years')->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
        });

        return response()->json($this->table('school_years')->find($id), 201);
    }

    private function assignmentAccess(int $id, bool $write = false): object
    {
        return $this->access->assignment($id, $write);
    }

    public function assignments(): JsonResponse
    {
        $rows = $this->access->assigned('assignments')->when($this->user()->role === 'student', fn ($q) => $q->where('published', true)->whereNotIn('lifecycle', ['draft', 'archived']))->orderByDesc('created_at')->get();
        foreach ($rows as $row) {
            $row->question_count = $this->table('assignment_questions')->where('assignment_id', $row->id)->count();
            $row->student_ids = $this->table('assignment_students')->where('assignment_id', $row->id)->pluck('student_id');
            $row->student_count = $row->student_ids->count();
            $row->submitted_count = $this->table('submissions')->where('assignment_id', $row->id)->whereIn('student_id', $row->student_ids)->whereIn('status', ['submitted', 'graded'])->whereNotNull('submitted_at')->count();
            if ($this->user()->role === 'student') {
                $row->my_submission = $this->table('submissions')->where('assignment_id', $row->id)->where('student_id', $this->user()->id)->first();
            }
        }

        return response()->json($rows);
    }

    public function assignment(int $id): JsonResponse
    {
        $assignment = $this->assignmentAccess($id);
        $assignment->student_ids = $this->table('assignment_students')->where('assignment_id', $id)->pluck('student_id');
        $assignment->question_count = $this->table('assignment_questions')->where('assignment_id', $id)->count();
        $assignment->attachments = $this->table('attachments')->where('assignment_id', $id)->get();
        if ($this->user()->role === 'student') {
            $assignment->my_submission = $this->table('submissions')->where('assignment_id', $id)->where('student_id', $this->user()->id)->first();
        }

        return response()->json($assignment);
    }

    public function saveAssignment(Request $request, ?int $id = null): JsonResponse
    {
        $this->requireRole('admin', 'teacher');
        $data = $request->validate(['title' => ['required', 'string', 'max:180'], 'instructions' => ['required', 'string'], 'criteria' => ['nullable', 'string'], 'max_score' => ['required', 'numeric', 'gt:0', 'lte:100'], 'starts_at' => ['nullable', 'date'], 'due_at' => ['required', 'date', 'after:now'], 'submission_type' => ['required', Rule::in(['file', 'text', 'both'])], ...$this->access->recipientRules(), 'subject_id' => ['nullable', 'integer', $this->access->exists('subjects')], 'published' => ['boolean']]);
        if (! empty($data['starts_at']) && strtotime($data['starts_at']) >= strtotime($data['due_at'])) {
            throw ValidationException::withMessages(['due_at' => 'Hạn nộp phải sau ngày bắt đầu.']);
        }
        $studentIds = $data['student_ids'];
        unset($data['student_ids']);
        $before = $id ? $this->assignmentAccess($id, true) : null;
        $previousStudents = $id ? $this->table('assignment_students')->where('assignment_id', $id)->pluck('student_id')->all() : [];
        if ($before && $this->table('assignment_attempts')->where('assignment_id', $id)->exists()) {
            abort_if((float) $before->max_score !== (float) $data['max_score'], 422, 'Không thể đổi thang điểm khi đã có lượt làm bài.');
        }
        $assignmentId = DB::transaction(function () use ($id, $data, $studentIds) {
            if ($id) {
                $this->table('assignments')->where('id', $id)->update([...$data, 'updated_at' => now()]);

                $assignmentId = $id;
            } else {
                $assignmentId = $this->table('assignments')->insertGetId([...$data, 'teacher_id' => $this->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->access->setRecipients('assignment_students', 'assignment_id', $assignmentId, $studentIds, $data['teacher_id'] ?? $this->table('assignments')->where('id', $assignmentId)->value('teacher_id'));

            return $assignmentId;
        });
        $this->auditChange($before ? 'updated' : 'created', 'assignment', $assignmentId, $before, $data);
        if ($data['published'] ?? $before?->published ?? true) {
            $newRecipients = ! $before || ! $before->published ? $studentIds : array_values(array_diff($studentIds, $previousStudents));
            $this->notifyUsers($newRecipients, 'Bài tập mới', $data['title'].' · Hạn nộp '.date('d/m/Y H:i', strtotime($data['due_at'])), 'assignment', '/assignments/'.$assignmentId);
        }

        return response()->json($this->table('assignments')->find($assignmentId), $before ? 200 : 201);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id): JsonResponse {
            $this->requireRole('student');
            $assignment = $this->assignmentAccess($id);
            abort_if($this->table('assignment_questions')->where('assignment_id', $id)->exists(), 422, 'Bài tập này yêu cầu làm từng câu hỏi trong mục lượt làm bài.');
            $this->table('users')->where('id', $this->user()->id)->lockForUpdate()->first();
            abort_unless($assignment->published, 404);
            $data = $request->validate(['answer' => ['nullable', 'string'], 'file' => ['nullable', 'file', 'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp,mp4', 'max:51200'], 'status' => ['required', Rule::in(['draft', 'submitted'])]]);
            if ($data['status'] === 'submitted' && empty($data['answer']) && ! $request->hasFile('file') && ! $this->table('attachments')->whereIn('submission_id', $this->table('submissions')->where('assignment_id', $id)->where('student_id', $this->user()->id)->select('id'))->exists()) {
                throw ValidationException::withMessages(['answer' => 'Vui lòng nhập câu trả lời hoặc đính kèm tệp trước khi nộp.']);
            }
            $existing = $this->table('submissions')->where('assignment_id', $id)->where('student_id', $this->user()->id)->first();
            if ($assignment->starts_at && now()->lessThan($assignment->starts_at)) {
                abort(422, 'Bài tập chưa bắt đầu.');
            }
            if ($existing && in_array($existing->status, ['submitted', 'graded'], true)) {
                abort(422, 'Bài đã được nộp. Vui lòng liên hệ giáo viên nếu cần nộp lại.');
            }
            if ($data['status'] === 'submitted' && now()->greaterThan($assignment->due_at) && $existing?->status !== 'resubmit') {
                abort(422, 'Đã quá hạn nộp bài. Vui lòng liên hệ giáo viên.');
            }
            if ($data['status'] === 'submitted' && $assignment->submission_type === 'file' && ! $request->hasFile('file') && ! $this->table('attachments')->where('submission_id', $existing?->id)->exists()) {
                throw ValidationException::withMessages(['file' => 'Bài tập này yêu cầu tệp đính kèm.']);
            }
            if ($data['status'] === 'submitted' && $assignment->submission_type === 'text' && empty($data['answer'])) {
                throw ValidationException::withMessages(['answer' => 'Bài tập này yêu cầu câu trả lời bằng văn bản.']);
            }
            $payload = ['answer' => $data['answer'] ?? $existing?->answer, 'status' => $data['status'], 'submitted_at' => $data['status'] === 'submitted' ? now() : null, 'updated_at' => now()];
            if ($existing) {
                $this->table('submissions')->where('id', $existing->id)->update($payload);
                $submissionId = $existing->id;
            } else {
                $submissionId = $this->table('submissions')->insertGetId([...$payload, 'assignment_id' => $id, 'student_id' => $this->user()->id, 'created_at' => now()]);
            }
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $this->access->storeFile($file, 'submissions', ['submission_id' => $submissionId]);
            }
            if ($data['status'] === 'submitted') {
                $this->recordSubmissionVersion($submissionId);
                $this->notifyUsers([$assignment->teacher_id], 'Học sinh đã nộp bài', $this->user()->name.' đã nộp “'.$assignment->title.'”.', 'submission', '/assignments/'.$id);
            }

            return response()->json($this->table('submissions')->find($submissionId));
        });
    }

    private function recordSubmissionVersion(int $id): void
    {
        $row = $this->table('submissions')->where('id', $id)->lockForUpdate()->first();
        $version = (int) $this->table('submission_versions')->where('submission_id', $id)->max('version') + 1;
        $this->table('submission_versions')->insert(['submission_id' => $id, 'version' => $version, 'snapshot' => json_encode($row, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function submissionHistory(int $id): JsonResponse
    {
        $submission = $this->table('submissions')->where('id', $id)->first();
        abort_unless($submission, 404);
        if ($submission->student_id !== $this->user()->id) {
            $this->assignmentAccess($submission->assignment_id, true);
        }
        $rows = $this->table('submission_versions')->where('submission_id', $id)->orderBy('version')->get();
        foreach ($rows as $row) {
            $row->snapshot = json_decode($row->snapshot, true);
        }

        return response()->json($rows);
    }

    public function submissions(int $id): JsonResponse
    {
        $this->requireRole('teacher', 'admin');
        $this->assignmentAccess($id, true);
        $students = $this->table('users')->whereIn('id', $this->table('assignment_students')->where('assignment_id', $id)->select('student_id'))->select('id', 'name', 'email')->orderBy('name')->get();
        foreach ($students as $student) {
            $student->submission = $this->table('submissions')->where('assignment_id', $id)->where('student_id', $student->id)->first();
            if ($student->submission) {
                $student->submission->attachments = $this->table('attachments')->where('submission_id', $student->submission->id)->get();
            }
        }

        return response()->json($students);
    }

    public function grade(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id): JsonResponse {
            $this->requireRole('teacher', 'admin');
            $submission = $this->table('submissions')->where('id', $id)->lockForUpdate()->first();
            abort_unless($submission, 404);
            $assignment = $this->assignmentAccess($submission->assignment_id, true);
            abort_if($this->table('assignment_questions')->where('assignment_id', $assignment->id)->exists(), 422, 'Vui lòng chấm từng lượt làm bài để giữ đúng chính sách điểm cuối.');
            abort_unless($submission->submitted_at, 422, 'Học sinh chưa nộp bài này.');
            $data = $request->validate(['score' => ['nullable', 'numeric', 'min:0', 'lte:'.$assignment->max_score], 'feedback' => ['nullable', 'string'], 'request_resubmission' => ['boolean'], 'reason' => ['nullable', 'string', 'max:1000']]);
            $status = ($data['request_resubmission'] ?? false) ? 'resubmit' : 'graded';
            if ($status === 'graded' && ! isset($data['score'])) {
                throw ValidationException::withMessages(['score' => 'Vui lòng nhập điểm trước khi chấm bài.']);
            }
            abort_if($status === 'graded' && $submission->status === 'graded' && empty($data['reason']), 422, 'Điều chỉnh điểm cần ghi rõ lý do.');
            $this->table('submissions')->where('id', $id)->update(['score' => $data['score'] ?? null, 'feedback' => $data['feedback'] ?? null, 'status' => $status, 'graded_by' => $this->user()->id, 'graded_at' => now(), 'updated_at' => now()]);
            $this->auditChange('graded', 'submission', $id, $submission, $this->table('submissions')->find($id));
            $this->recordSubmissionVersion($id);
            if (! empty($data['reason'])) {
                $this->auditChange('grade_override_reason', 'submission', $id, null, ['reason' => $data['reason']]);
            }
            $this->notifyUsers([$submission->student_id], $status === 'graded' ? 'Bài tập đã được chấm' : 'Cần nộp lại bài tập', $assignment->title, 'grade', '/assignments/'.$assignment->id);

            return response()->json($this->table('submissions')->find($id));
        });
    }

    public function uploadAttachment(Request $request, int $id): JsonResponse
    {
        $this->assignmentAccess($id, true);
        $data = $request->validate(['file' => ['required_without:url', 'file', 'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp,mp4', 'max:51200'], 'url' => ['required_without:file', 'nullable', 'url:http,https', 'max:2048']]);
        $file = $request->file('file');
        $attachmentId = $file ? $this->access->storeFile($file, 'assignments', ['assignment_id' => $id]) : $this->table('attachments')->insertGetId(['assignment_id' => $id, 'name' => $data['url'], 'url' => $data['url'], 'created_at' => now(), 'updated_at' => now()]);

        return response()->json($this->table('attachments')->find($attachmentId), 201);
    }

    public function downloadAttachment(int $id): StreamedResponse
    {
        $file = $this->access->file($id);
        abort_if($this->user()->role === 'student' && ! $file->download_allowed, 403, 'Tài liệu này chỉ cho phép xem trước.');
        abort_unless($file->path, 404);

        return Storage::disk($file->disk)->download($file->path, $file->name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function lessons(Request $request): JsonResponse
    {
        $this->requireRole('teacher', 'admin', 'student', 'parent');
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $from = $request->date('from')?->startOfDay() ?? now()->startOfWeek();
        $to = $request->date('to')?->endOfDay() ?? now()->endOfWeek();
        $this->timetable->materialize($from, $to);
        $rows = $this->access->assigned('lessons')->join('subjects', 'lessons.subject_id', '=', 'subjects.id')->join('users', 'lessons.teacher_id', '=', 'users.id')->whereBetween('starts_at', [$from, $to])->select('lessons.*', 'subjects.name as subject_name', 'users.name as teacher_name')->orderBy('starts_at')->get();
        foreach ($rows as $row) {
            $row->student_ids = $this->table('lesson_students')->where('lesson_id', $row->id)->pluck('student_id');
        }

        return response()->json($rows);
    }

    public function saveLesson(Request $request, ?int $id = null): JsonResponse
    {
        return DB::transaction(function () use ($request, $id): JsonResponse {
            $this->timetable->lock();
            $this->requireRole('admin', 'teacher');
            $data = $request->validate([...$this->access->recipientRules(), 'subject_id' => ['required', $this->access->exists('subjects')], 'teacher_id' => ['required', $this->access->exists('users')->where('role', 'teacher')], 'title' => ['required', 'string', 'max:180'], 'room' => ['nullable', 'string', 'max:80'], 'period' => ['nullable', 'integer', 'between:1,12'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'status' => ['required', Rule::in(['scheduled', 'rescheduled', 'makeup', 'cancelled'])], 'note' => ['nullable', 'string']]);
            abort_if($this->user()->role === 'teacher' && (int) $data['teacher_id'] !== $this->user()->id, 403);
            foreach (['starts_at', 'ends_at'] as $field) {
                $data[$field] = CarbonImmutable::parse($data[$field], config('app.timezone'))->setTimezone(config('app.timezone'))->toDateTimeString();
            }
            $before = $id ? $this->table('lessons')->find($id) : null;
            if ($id) {
                abort_unless($before, 404);
                $this->access->resource('lessons', $id, true);
                if ($this->user()->role === 'teacher' && ! $this->attendanceEditAccess($before)['can_edit']) {
                    $timezone = config('app.timezone');
                    $originalDate = CarbonImmutable::parse($before->starts_at, $timezone)->setTimezone($timezone)->toDateString();
                    $requestedDate = CarbonImmutable::parse($data['starts_at'], $timezone)->setTimezone($timezone)->toDateString();
                    abort_unless($originalDate === $requestedDate, 403, 'Không thể đổi ngày của tiết học đã qua. Vui lòng liên hệ quản trị viên nếu cần điều chỉnh.');
                }
            }
            $this->timetable->conflict($data, $id);
            if ($before?->recurrence_id) {
                $data['is_exception'] = true;
            }
            $studentIds = $data['student_ids'];
            unset($data['student_ids']);
            if ($id) {
                $this->table('lessons')->where('id', $id)->update([...$data, 'updated_at' => now()]);
            } else {
                $id = $this->table('lessons')->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->access->setRecipients('lesson_students', 'lesson_id', $id, $studentIds, (int) $data['teacher_id']);
            $this->auditChange($before ? 'updated' : 'created', 'lesson', $id, $before, $data);
            if ($before && ($before->starts_at !== $data['starts_at'] || $before->room !== ($data['room'] ?? null) || $before->status !== $data['status'])) {
                $this->notifyUsers($studentIds, 'Thời khóa biểu thay đổi', $data['title'].' · '.date('d/m/Y H:i', strtotime($data['starts_at'])), 'schedule');
            }

            return response()->json($this->table('lessons')->find($id), $before ? 200 : 201);
        });
    }

    public function attendance(int $id): JsonResponse
    {
        $lesson = $this->table('lessons')->find($id);
        abort_unless($lesson, 404);
        $this->access->resource('lessons', $id);
        $rows = $this->table('lesson_students')->join('users', 'lesson_students.student_id', '=', 'users.id')->leftJoin('attendance_records', function ($join) use ($id) {
            $join->on('attendance_records.student_id', '=', 'users.id')->where('attendance_records.lesson_id', '=', $id);
        })->where('lesson_students.lesson_id', $id)->select('users.id', 'users.name', 'attendance_records.status', 'attendance_records.note')->orderBy('users.name')->get();
        foreach ($rows as $row) {
            $row->status ??= 'present';
        }
        if (in_array($this->user()->role, ['student', 'parent'], true)) {
            $rows = $rows->whereIn('id', $this->access->studentIds())->values();
        }

        return response()->json(['lesson' => $lesson, 'students' => $rows, ...$this->attendanceEditAccess($lesson)]);
    }

    /** @return array{can_edit: bool, locked_reason: ?string, editable_until: ?string} */
    private function attendanceEditAccess(object $lesson): array
    {
        $timezone = config('app.timezone');
        $deadline = CarbonImmutable::parse($lesson->starts_at, $timezone)->setTimezone($timezone)->startOfDay()->addDay();
        $teacher = $this->user()->role === 'teacher';
        $pastDate = now($timezone)->greaterThanOrEqualTo($deadline);

        return [
            'can_edit' => $this->user()->role === 'admin' || ($teacher && ! $pastDate),
            'locked_reason' => $teacher && $pastDate ? 'Điểm danh của ngày đã qua đã được khóa. Vui lòng liên hệ quản trị viên nếu cần điều chỉnh.' : null,
            'editable_until' => $teacher ? $deadline->toIso8601String() : null,
        ];
    }

    public function saveAttendance(Request $request, int $id): JsonResponse
    {
        $this->requireRole('admin', 'teacher');
        $lesson = $this->table('lessons')->find($id);
        abort_unless($lesson, 404);
        $this->access->resource('lessons', $id, true);
        $access = $this->attendanceEditAccess($lesson);
        abort_unless($access['can_edit'], 403, $access['locked_reason']);
        $data = $request->validate(['records' => ['required', 'array', 'max:1000'], 'records.*.student_id' => ['required', 'integer', 'distinct', $this->access->exists('users')], 'records.*.status' => ['required', Rule::in(['present', 'late', 'excused', 'unexcused'])], 'records.*.note' => ['nullable', 'string', 'max:1000']]);
        $memberIds = $this->table('lesson_students')->where('lesson_id', $id)->pluck('student_id')->all();
        foreach ($data['records'] as $record) {
            abort_unless(in_array($record['student_id'], $memberIds), 422, 'Có học sinh không được xếp vào buổi học này.');
        }
        DB::transaction(function () use ($data, $id, $memberIds) {
            $byStudent = collect($data['records'])->keyBy('student_id');
            foreach ($memberIds as $studentId) {
                $before = $this->table('attendance_records')->where('lesson_id', $id)->where('student_id', $studentId)->first();
                $entry = $byStudent->get($studentId, ['status' => $before?->status ?? 'present', 'note' => $before?->note]);
                $payload = ['status' => $entry['status'], 'note' => $entry['note'] ?? null, 'recorded_by' => $this->user()->id, 'updated_at' => now()];
                $this->table('attendance_records')->updateOrInsert(['lesson_id' => $id, 'student_id' => $studentId], [...$payload, 'created_at' => $before?->created_at ?? now()]);
                if (! $before || $before->status !== $entry['status'] || $before->note !== ($entry['note'] ?? null)) {
                    $this->auditChange($before ? 'updated' : 'created', 'attendance', $id, $before, ['student_id' => $studentId, ...$payload]);
                    if (in_array($entry['status'], ['late', 'excused', 'unexcused'])) {
                        $this->notifyUsers([$studentId], 'Điểm danh đã cập nhật', 'Trạng thái điểm danh của bạn đã được cập nhật.', 'attendance');
                    }
                }
            }
        });

        return response()->json(['message' => 'Đã lưu điểm danh cho buổi học.']);
    }

    public function games(): JsonResponse
    {
        $rows = $this->access->assigned('games')->orderByDesc('created_at')->get();
        foreach ($rows as $row) {
            $row->question_count = $this->table('questions')->where('game_id', $row->id)->count();
            $row->student_ids = $this->table('game_students')->where('game_id', $row->id)->pluck('student_id');
            if ($this->user()->role === 'student') {
                $row->attempt_count = $this->table('game_attempts')->where('game_id', $row->id)->where('student_id', $this->user()->id)->count();
                $row->best_score = $this->table('game_attempts')->where('game_id', $row->id)->where('student_id', $this->user()->id)->max('score');
            }
        }

        return response()->json($rows);
    }

    public function saveGame(Request $request): JsonResponse
    {
        $this->requireRole('admin', 'teacher');
        $data = $request->validate(['title' => ['required', 'string', 'max:180'], ...$this->access->recipientRules(), 'subject_id' => ['nullable', 'integer', $this->access->exists('subjects')], 'type' => ['required', Rule::in(['quiz', 'true_false', 'matching', 'ordering', 'fill_blank', 'timed', 'word_builder', 'listening', 'challenge'])], 'graded' => ['boolean'], 'time_limit_seconds' => ['nullable', 'integer', 'min:10', 'max:3600'], 'due_at' => ['nullable', 'date'], 'allowed_attempts' => ['nullable', 'integer', 'between:0,100'], 'required_xp' => ['nullable', 'integer', 'between:0,100000'], 'level' => ['nullable', 'integer', 'between:1,100'], 'questions' => ['required', 'array', 'min:1', 'max:100'], 'questions.*.kind' => ['nullable', Rule::in(QuestionEngine::TYPES)], 'questions.*.media_url' => ['nullable', 'url:https', 'max:2048'], 'questions.*.prompt' => ['required', 'string'], 'questions.*.options' => ['present', 'array', 'max:100'], 'questions.*.answer' => ['required'], 'questions.*.topic' => ['nullable', 'string', 'max:100']]);
        $questions = $data['questions'];
        $students = $data['student_ids'];
        unset($data['questions'], $data['student_ids']);
        $id = DB::transaction(function () use ($data, $questions, $students) {
            $gameId = $this->table('games')->insertGetId([...$data, 'teacher_id' => $this->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->access->setRecipients('game_students', 'game_id', $gameId, $students, $this->user()->id);
            foreach ($questions as $index => $question) {
                $kind = $question['kind'] ?? match ($data['type']) {
                    'matching' => 'matching', 'ordering' => 'ordering', 'fill_blank' => 'fill_blank', 'word_builder' => 'word_builder', 'listening' => 'audio', default => 'single'
                };
                abort_if(in_array($kind, ['essay', 'short', 'file'], true), 422, 'Trò chơi chỉ hỗ trợ câu hỏi chấm tự động.');
                $question['kind'] = $kind;
                $this->questionEngine->validateDefinition($question);
                $this->table('questions')->insert(['game_id' => $gameId, 'prompt' => $question['prompt'], 'options' => json_encode($question['options'], JSON_UNESCAPED_UNICODE), 'answer' => json_encode($question['answer'], JSON_UNESCAPED_UNICODE), 'kind' => $question['kind'] ?? 'single', 'media_url' => $question['media_url'] ?? null, 'topic' => $question['topic'] ?? null, 'sort_order' => $index, 'created_at' => now(), 'updated_at' => now()]);
            }

            return $gameId;
        });
        $this->notifyUsers($students, 'Trò chơi mới', $data['title'], 'game', '/games/'.$id);

        return response()->json($this->table('games')->find($id), 201);
    }

    public function game(int $id): JsonResponse
    {
        $game = $this->table('games')->find($id);
        abort_unless($game, 404);
        $this->access->resource('games', $id);
        $questions = $this->table('questions')->where('game_id', $id)->orderBy('sort_order')->get();
        foreach ($questions as $question) {
            $question->options = json_decode($question->options, true);
            if ($this->user()->role === 'student') {
                unset($question->answer);
            } else {
                $question->answer = json_decode($question->answer, true);
            }
        }
        $game->questions = $questions;

        return response()->json($game);
    }

    public function progress(Request $request): JsonResponse
    {
        $ids = $this->access->studentIds();
        if ($request->filled('student_id')) {
            abort_unless(in_array($request->integer('student_id'), $ids, true), 404);
            $ids = [$request->integer('student_id')];
        }
        $students = $this->table('users')->whereIn('id', $ids)->select('id', 'name', 'email')->orderBy('name')->get();
        $availableAssignments = $this->access->assigned('assignments')->where('published', true)->pluck('id');
        $availableLessons = $this->access->assigned('lessons')->where('starts_at', '<=', now())->pluck('id');
        $availableGames = $this->access->assigned('games')->pluck('id');
        $attention = (int) $this->table('app_settings')->where('key', 'attention_threshold')->value('value') ?: 70;
        $risk = (int) $this->table('app_settings')->where('key', 'risk_threshold')->value('value') ?: 50;
        foreach ($students as $student) {
            $assignmentIds = $this->table('assignment_students')->where('student_id', $student->id)->whereIn('assignment_id', $availableAssignments)->pluck('assignment_id')->all();
            $lessonIds = $this->table('lesson_students')->where('student_id', $student->id)->whereIn('lesson_id', $availableLessons)->pluck('lesson_id')->all();
            $gameIds = $this->table('game_students')->where('student_id', $student->id)->whereIn('game_id', $availableGames)->pluck('game_id')->all();
            $submissions = $this->table('submissions')->where('student_id', $student->id)->whereIn('assignment_id', $assignmentIds)->get();
            $submitted = $submissions->whereNotNull('submitted_at')->whereIn('status', ['submitted', 'graded'])->count();
            $student->completion_rate = count($assignmentIds) ? round(100 * $submitted / count($assignmentIds)) : 100;
            $graded = $submissions->where('status', 'graded')->whereNotNull('score');
            $student->average_score = $graded->count() ? round($graded->avg('score'), 1) : null;
            $student->missing_count = max(0, count($assignmentIds) - $submitted);
            $student->late_count = $this->table('submissions')->join('assignments', 'submissions.assignment_id', '=', 'assignments.id')->where('submissions.student_id', $student->id)->whereIn('submissions.assignment_id', $assignmentIds)->whereColumn('submissions.submitted_at', '>', 'assignments.due_at')->count();
            $present = $this->table('attendance_records')->where('student_id', $student->id)->whereIn('lesson_id', $lessonIds)->whereIn('status', ['present', 'late'])->count();
            $student->attendance_rate = count($lessonIds) ? round(100 * $present / count($lessonIds)) : 100;
            $student->game_average = $this->table('game_attempts')->where('student_id', $student->id)->whereIn('game_id', $gameIds)->avg('score');
            $student->weak_topics = $this->table('game_attempts')->where('student_id', $student->id)->whereIn('game_id', $gameIds)->pluck('wrong_topics')->flatMap(fn ($value) => json_decode($value, true) ?: [])->countBy()->sortDesc()->take(3)->keys()->values();
            $student->note = $this->table('support_notes')->where('student_id', $student->id)->when($this->user()->role === 'teacher', fn ($q) => $q->where('teacher_id', $this->user()->id))->latest('id')->first();
            $indicator = min($student->completion_rate, $student->attendance_rate);
            $student->level = $student->note?->level ?? ($indicator < $risk ? 'red' : ($indicator < $attention ? 'yellow' : 'green'));
        }

        return response()->json($students);
    }

    public function saveSupportNote(Request $request, int $studentId): JsonResponse
    {
        $this->requireRole('admin', 'teacher');
        abort_unless(in_array($studentId, $this->access->studentIds(), true), 404);
        $data = $request->validate(['level' => ['required', Rule::in(['green', 'yellow', 'red'])], 'note' => ['required', 'string'], 'plan' => ['nullable', 'string']]);
        $id = $this->table('support_notes')->insertGetId([...$data, 'student_id' => $studentId, 'teacher_id' => $this->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->auditChange('created', 'support_note', $id, null, $data);

        return response()->json($this->table('support_notes')->find($id), 201);
    }

    public function notifications(): JsonResponse
    {
        return response()->json($this->table('notifications')->where('user_id', $this->user()->id)->orderByDesc('created_at')->limit(100)->get());
    }

    public function readNotifications(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['integer']]);
        $this->table('notifications')->where('user_id', $this->user()->id)->when(! empty($data['ids']), fn ($query) => $query->whereIn('id', $data['ids']))->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Đã đánh dấu thông báo là đã đọc.']);
    }

    public function resources(): JsonResponse
    {
        $this->requireRole('admin', 'teacher');

        return response()->json($this->table('attachments')->where('resource_owner_id', $this->user()->id)->whereNull('archived_at')->orderByDesc('created_at')->get());
    }

    public function audit(): JsonResponse
    {
        $this->requireRole('admin');

        return response()->json($this->table('audit_logs')->leftJoin('users', 'audit_logs.actor_id', '=', 'users.id')->select('audit_logs.*', 'users.name as actor_name')->orderByDesc('audit_logs.id')->limit(100)->get());
    }

    public function settings(): JsonResponse
    {
        $this->requireRole('admin');

        return response()->json($this->table('app_settings')->pluck('value', 'key'));
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->requireRole('admin');
        $data = $request->validate(['attention_threshold' => ['required', 'integer', 'between:1,100'], 'risk_threshold' => ['required', 'integer', 'between:1,100', 'lt:attention_threshold']]);
        foreach ($data as $key => $value) {
            $this->table('app_settings')->updateOrInsert(['key' => $key], ['value' => (string) $value, 'updated_at' => now(), 'created_at' => now()]);
        }
        $this->auditChange('updated', 'settings', 1, null, $data);

        return response()->json($data);
    }
}
