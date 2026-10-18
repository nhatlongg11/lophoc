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
use Symfony\Component\HttpFoundation\StreamedResponse;

class EnrollmentController extends Controller
{
    public function __construct(private LearningAccess $access, private RosterImporter $importer) {}

    public function import(Request $request, int $id): JsonResponse
    {
        $this->access->requireRole('admin');
        $this->access->classAccess($id, true);
        $data = $request->validate(['file' => ['required', 'file', 'extensions:csv,xlsx', 'max:5120']]);
        $rows = $this->importer->parse($data['file']);
        Validator::make(['rows' => $rows], ['rows' => ['array', 'max:1000'], 'rows.*.name' => ['required', 'string', 'max:160'], 'rows.*.email' => ['required', 'email', 'max:254', 'distinct'], 'rows.*.phone' => ['nullable', 'string', 'max:30']])->validate();
        $count = DB::transaction(function () use ($rows, $id) {
            $count = 0;
            foreach ($rows as $row) {
                $student = User::where('email', $row['email'])->lockForUpdate()->first();
                if ($student) {
                    abort_unless($student->organization_id === $this->access->user()->organization_id && $student->role === 'student', 422, 'Có email không thể thêm vào lớp này. Toàn bộ lần nhập đã được hủy.');
                } else {
                    $this->access->enforceLimit('students');
                    $student = User::create([...$row, 'organization_id' => $this->access->user()->organization_id, 'role' => 'student', 'active' => true, 'password' => Str::random(64)]);
                }
                abort_if($this->access->table('class_memberships')->where('student_id', $student->id)->where('class_id', '!=', $id)->exists(), 422, 'Có học sinh đã thuộc lớp khác. Hãy dùng chuyển lớp.');
                $count += $this->access->table('class_memberships')->insertOrIgnore(['class_id' => $id, 'student_id' => $student->id, 'created_at' => now(), 'updated_at' => now()]);
                $this->access->audit('imported', 'class_membership', $id, null, ['student_id' => $student->id]);
            }

            return $count;
        });

        return response()->json(['added' => $count, 'message' => 'Đã nhập '.$count.' học sinh. Tạo lời mời riêng để học sinh mới đặt mật khẩu.']);
    }

    public function export(int $id): StreamedResponse
    {
        $this->access->requireRole('teacher', 'admin');
        $this->access->classAccess($id);
        $this->access->audit('exported', 'class_roster', $id);

        return response()->streamDownload(function () use ($id) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['name', 'email', 'phone'], ',', '"', '');
            foreach ($this->access->table('users')->join('class_memberships', 'users.id', '=', 'class_memberships.student_id')->where('class_id', $id)->select('users.name', 'users.email', 'users.phone')->orderBy('users.id')->cursor() as $row) {
                $values = array_map(fn ($v) => preg_match('/^[\s]*[=+\-@]/u', (string) $v) ? "'".$v : (string) $v, [$row->name, $row->email, $row->phone]);
                fputcsv($output, $values, ',', '"', '');
            }
            fclose($output);
        }, 'class-'.$id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function archive(int $id): JsonResponse
    {
        $this->access->requireRole('admin');
        $this->access->classAccess($id, true);
        DB::transaction(function () use ($id) {
            $this->access->table('school_classes')->where('id', $id)->update(['archived_at' => now()]);
            $this->access->audit('archived', 'class', $id);
        });

        return response()->json(['message' => 'Đã lưu trữ lớp; dữ liệu học tập được giữ nguyên.']);
    }
}
