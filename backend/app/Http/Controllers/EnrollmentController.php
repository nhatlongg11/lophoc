<?php

namespace App\Http\Controllers;

use App\LearningAccess;
use App\Models\User;
use App\RosterImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EnrollmentController extends Controller
{
    private const JOIN_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private LearningAccess $access, private RosterImporter $importer) {}

    public function joinCode(): JsonResponse
    {
        $this->access->requireRole('teacher');
        $code = DB::transaction(function (): string {
            $user = User::where('id', $this->access->user()->id)->lockForUpdate()->firstOrFail();
            if (! $user->join_code) {
                $user->forceFill(['join_code' => $this->uniqueJoinCode()])->save();
            }

            return $user->join_code;
        });

        return response()->json($this->joinCodePayload($code));
    }

    public function refreshJoinCode(): JsonResponse
    {
        $this->access->requireRole('teacher');
        $code = $this->uniqueJoinCode();
        $this->access->table('users')->where('id', $this->access->user()->id)->update(['join_code' => $code]);
        $this->access->audit('class_code_refreshed', 'user', $this->access->user()->id);

        return response()->json($this->joinCodePayload($code));
    }

    private function uniqueJoinCode(): string
    {
        do {
            $code = '';
            for ($index = 0; $index < 8; $index++) {
                $code .= self::JOIN_CODE_ALPHABET[random_int(0, strlen(self::JOIN_CODE_ALPHABET) - 1)];
            }
        } while (User::where('join_code', $code)->exists());

        return $code;
    }

    /**
     * @return array{code: string, url: string}
     */
    private function joinCodePayload(string $code): array
    {
        return ['code' => $code, 'url' => url('/app').'/?join='.$code];
    }

    public function studentClasses(): JsonResponse
    {
        $this->access->requireRole('student');
        $classes = $this->access->table('teacher_students')
            ->join('users', 'teacher_students.teacher_id', '=', 'users.id')
            ->where('teacher_students.student_id', $this->access->user()->id)
            ->where('users.organization_id', $this->access->user()->organization_id)
            ->where('users.role', 'teacher')
            ->where('users.active', true)
            ->where(function ($query): void {
                $query->whereNull('users.registration_status')->orWhere('users.registration_status', 'approved');
            })
            ->orderBy('users.name')
            ->select('users.id', 'users.name')
            ->get();

        return response()->json($classes);
    }

    public function joinClass(Request $request): JsonResponse
    {
        $this->access->requireRole('student');
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $student = $this->access->user();
        $teacher = User::where('join_code', Str::upper(trim($data['code'])))
            ->where('organization_id', $student->organization_id)
            ->where('role', 'teacher')
            ->where('active', true)
            ->where(function ($query): void {
                $query->whereNull('registration_status')->orWhere('registration_status', 'approved');
            })
            ->first();
        if (! $teacher) {
            throw ValidationException::withMessages(['code' => 'Mã lớp không hợp lệ hoặc đã được thay đổi. Vui lòng kiểm tra lại.']);
        }
        $joined = DB::transaction(function () use ($teacher, $student): bool {
            $joined = $this->access->table('teacher_students')->insertOrIgnore(['teacher_id' => $teacher->id, 'student_id' => $student->id]) > 0;
            if ($joined) {
                $this->access->audit('class_joined', 'user', $student->id, null, ['teacher_id' => $teacher->id]);
            }

            return $joined;
        });

        return response()->json([
            'message' => $joined ? 'Đã tham gia lớp của '.$teacher->name.'.' : 'Em đã tham gia lớp này rồi.',
            'joined' => $joined,
            'teacher' => ['id' => $teacher->id, 'name' => $teacher->name],
        ], $joined ? 201 : 200);
    }

    public function import(Request $request): JsonResponse
    {
        $this->access->requireRole('admin');
        $data = $request->validate(['file' => ['required', 'file', 'extensions:csv,xlsx', 'max:5120']]);
        $rows = $this->importer->parse($data['file']);
        Validator::make(['rows' => $rows], ['rows' => ['array', 'max:1000'], 'rows.*.name' => ['required', 'string', 'max:160'], 'rows.*.email' => ['required', 'email', 'max:254', 'distinct'], 'rows.*.phone' => ['nullable', 'string', 'max:30']])->validate();
        $count = DB::transaction(function () use ($rows) {
            $count = 0;
            foreach ($rows as $row) {
                $student = User::where('email', $row['email'])->lockForUpdate()->first();
                if ($student) {
                    abort_unless($student->organization_id === $this->access->user()->organization_id && $student->role === 'student', 422, 'Có email không thể thêm vào danh sách học sinh. Toàn bộ lần nhập đã được hủy.');
                } else {
                    $this->access->enforceLimit('students');
                    $count++;
                    $student = User::create([...$row, 'organization_id' => $this->access->user()->organization_id, 'role' => 'student', 'active' => true, 'password' => Str::random(64)]);
                }
                $this->access->audit('imported', 'student', $student->id);
            }

            return $count;
        });

        return response()->json(['added' => $count, 'message' => 'Đã nhập '.$count.' học sinh. Tạo lời mời riêng để học sinh mới đặt mật khẩu.']);
    }

    public function export(): StreamedResponse
    {
        $this->access->requireRole('admin');
        $this->access->audit('exported', 'student_roster', 0);

        return response()->streamDownload(function () {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['name', 'email', 'phone'], ',', '"', '');
            foreach ($this->access->table('users')->where('role', 'student')->select('users.name', 'users.email', 'users.phone')->orderBy('users.id')->cursor() as $row) {
                $values = array_map(fn ($v) => preg_match('/^[\s]*[=+\-@]/u', (string) $v) ? "'".$v : (string) $v, [$row->name, $row->email, $row->phone]);
                fputcsv($output, $values, ',', '"', '');
            }
            fclose($output);
        }, 'students.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
