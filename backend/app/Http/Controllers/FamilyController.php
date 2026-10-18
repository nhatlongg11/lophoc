<?php

namespace App\Http\Controllers;

use App\FamilyAnalytics;
use App\LearningAccess;
use App\Models\User;
use App\Timetable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FamilyController extends Controller
{
    public function __construct(private LearningAccess $access, private FamilyAnalytics $analytics, private Timetable $timetable) {}

    public function profiles(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity_user');
        abort_unless(in_array($identity->role, ['family', 'parent'], true), 403);
        $children = $this->access->table('parent_student_links')->join('users', 'parent_student_links.student_id', '=', 'users.id')->where('parent_id', $identity->id)->where('users.active', true)->select('users.id', 'users.name')->get();

        return response()->json(['children' => $children, 'mode' => $request->attributes->get('account_session')->mode]);
    }

    public function select(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity_user');
        abort_unless(in_array($identity->role, ['family', 'parent'], true), 403);
        $data = $request->validate(['mode' => ['required', Rule::in(['student', 'parent'])], 'student_id' => ['required_if:mode,student', 'nullable', 'integer'], 'pin' => ['required_if:mode,parent', 'nullable', 'digits_between:6,12']]);
        $sessionId = $request->attributes->get('api_token_id');
        if ($data['mode'] === 'parent') {
            $key = 'parent-pin:'.$identity->id.':'.$request->ip();
            abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Nhập PIN quá nhiều lần. Vui lòng thử lại sau 15 phút.');
            if (! $identity->parent_pin_hash || ! Hash::check($data['pin'], $identity->parent_pin_hash)) {
                RateLimiter::hit($key, 900);
                $this->access->audit('parent_pin_failed', 'user', $identity->id);
                throw ValidationException::withMessages(['pin' => 'PIN phụ huynh không đúng.']);
            }
            RateLimiter::clear($key);
            $this->access->table('api_tokens')->where('id', $sessionId)->update(['mode' => 'parent', 'profile_student_id' => null, 'parent_verified_until' => now()->addMinutes(15)]);
            $user = clone $identity;
            $user->role = 'parent';
        } else {
            abort_unless($this->access->table('parent_student_links')->where('parent_id', $identity->id)->where('student_id', $data['student_id'])->exists(), 404);
            $user = User::where('organization_id', $identity->organization_id)->where('active', true)->where('role', 'student')->findOrFail($data['student_id']);
            $this->access->table('api_tokens')->where('id', $sessionId)->update(['mode' => 'student', 'profile_student_id' => $user->id, 'parent_verified_until' => null]);
        }
        $this->access->audit('profile_switched', 'user', $identity->id, null, ['mode' => $data['mode']]);

        return response()->json(['user' => $user]);
    }

    public function link(Request $request): JsonResponse
    {
        $this->access->requireRole('admin');
        $data = $request->validate(['parent_id' => ['required', $this->access->exists('users')->whereIn('role', ['parent', 'family'])], 'student_id' => ['required', $this->access->exists('users')->where('role', 'student')]]);
        $this->access->table('parent_student_links')->updateOrInsert($data, ['created_at' => now(), 'updated_at' => now()]);
        $this->access->audit('linked', 'family', (int) $data['parent_id'], null, $data);

        return response()->json(['message' => 'Đã liên kết gia đình.'], 201);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $this->access->requireRole('parent');
        $ids = $this->access->table('parent_student_links')->where('parent_id', $this->access->user()->id)->pluck('student_id')->all();
        if ($request->filled('student_id')) {
            abort_unless(in_array($request->integer('student_id'), $ids, true), 404);
            $ids = [$request->integer('student_id')];
        }
        $this->timetable->materialize(now()->startOfWeek(), now()->endOfWeek());
        $children = $this->access->table('users')->whereIn('id', $ids)->where('active', true)->select('id', 'name')->get();
        foreach ($children as $child) {
            $child->analytics = $this->analytics->child($child->id);
            $child->results = $this->access->table('submissions')->join('assignments', 'submissions.assignment_id', '=', 'assignments.id')->where('student_id', $child->id)->whereNotNull('submitted_at')->select('submissions.id', 'submissions.status', 'submissions.score', 'submissions.feedback', 'submissions.submitted_at', 'assignments.title', 'assignments.max_score')->orderByDesc('submitted_at')->limit(20)->get();
            $child->attendance = $this->access->table('attendance_records')->join('lessons', 'attendance_records.lesson_id', '=', 'lessons.id')->where('student_id', $child->id)->select('attendance_records.status', 'attendance_records.note', 'lessons.title', 'lessons.starts_at')->orderByDesc('starts_at')->limit(30)->get();
            $child->timetable = $this->access->table('lessons')->whereIn('id', $this->access->table('lesson_students')->where('student_id', $child->id)->select('lesson_id'))->whereBetween('starts_at', [now()->startOfWeek(), now()->endOfWeek()])->orderBy('starts_at')->get();
            $child->observations = $this->access->table('support_notes')->where('student_id', $child->id)->orderByDesc('id')->limit(10)->get();
            $child->homework = $this->access->table('assignments')->where('published', true)->whereIn('id', $this->access->table('assignment_students')->where('student_id', $child->id)->select('assignment_id'))->select('id', 'title', 'due_at')->orderBy('due_at')->limit(30)->get();
        }
        $this->access->audit('parent_records_viewed', 'family', $this->access->user()->id, null, ['student_ids' => $ids]);

        return response()->json(['children' => $children]);
    }
}
