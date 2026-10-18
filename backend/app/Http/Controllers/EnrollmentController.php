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
