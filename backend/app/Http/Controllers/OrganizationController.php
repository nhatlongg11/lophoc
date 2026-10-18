<?php

namespace App\Http\Controllers;

use App\LearningAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    public function __construct(private LearningAccess $access) {}

    public function show(): JsonResponse
    {
        $this->access->requireRole('admin');
        $org = DB::table('organizations')->where('id', $this->access->user()->organization_id)->first();

        return response()->json(['organization' => $org, 'usage' => ['students' => $this->access->table('users')->where('role', 'student')->where('active', true)->count(), 'teachers' => $this->access->table('users')->where('role', 'teacher')->where('active', true)->count(), 'storage_bytes' => (int) $this->access->table('attachments')->sum('size')], 'requests' => $this->access->table('billing_requests')->orderByDesc('id')->limit(20)->get(), 'plans' => ['free' => ['name' => 'Miễn phí', 'students' => 50, 'teachers' => 3], 'teacher_pro' => ['name' => 'Teacher Pro', 'students' => 200, 'teachers' => 5], 'center' => ['name' => 'Trung tâm', 'students' => 1000, 'teachers' => 30], 'school' => ['name' => 'Trường học', 'students' => 5000, 'teachers' => 200]]]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->access->requireRole('admin');
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'leaderboard_enabled' => ['required', 'boolean']]);
        DB::table('organizations')->where('id', $this->access->user()->organization_id)->update([...$data, 'updated_at' => now()]);
        $this->access->audit('updated', 'organization', $this->access->user()->organization_id, null, $data);

        return response()->json(['message' => 'Đã lưu cấu hình tổ chức.']);
    }

    public function requestPlan(Request $request): JsonResponse
    {
        $this->access->requireRole('admin');
        $data = $request->validate(['plan' => ['required', Rule::in(['free', 'teacher_pro', 'center', 'school'])]]);
        $id = DB::transaction(function () use ($data) {
            DB::table('organizations')->where('id', $this->access->user()->organization_id)->lockForUpdate()->first();
            abort_if($this->access->table('billing_requests')->where('status', 'pending')->exists(), 422, 'Đã có yêu cầu đổi gói đang chờ xử lý.');

            return $this->access->table('billing_requests')->insertGetId([...$data, 'requested_by' => $this->access->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->access->audit('plan_requested', 'billing_request', $id, null, $data);

        return response()->json(['message' => 'Đã ghi nhận yêu cầu đổi gói. Quản trị nền tảng sẽ xét duyệt.'], 201);
    }

    public function platform(): JsonResponse
    {
        $this->access->requireRole('super_admin');

        return response()->json(['organizations' => DB::table('organizations')->orderBy('id')->limit(100)->get(), 'requests' => DB::table('billing_requests')->join('organizations', 'billing_requests.organization_id', '=', 'organizations.id')->where('billing_requests.status', 'pending')->select('billing_requests.id', 'billing_requests.organization_id', 'billing_requests.plan', 'billing_requests.created_at', 'organizations.name')->orderBy('billing_requests.id')->limit(100)->get()]);
    }

    public function createOrganization(Request $request): JsonResponse
    {
        $this->access->requireRole('super_admin');
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'slug' => ['required', 'alpha_dash:ascii', 'max:80', 'unique:organizations,slug'], 'admin_email' => ['required', 'email', 'max:254']]);
        $token = Str::random(64);
        $id = DB::transaction(function () use ($data, $token) {
            $id = DB::table('organizations')->insertGetId(['name' => $data['name'], 'slug' => $data['slug'], 'created_at' => now(), 'updated_at' => now()]);
            DB::table('invitations')->insert(['organization_id' => $id, 'email' => $data['admin_email'], 'role' => 'admin', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(3), 'created_at' => now(), 'updated_at' => now()]);
            $this->access->audit('organization_created', 'organization', $id);

            return $id;
        });

        return response()->json(['id' => $id, 'invitation_url' => rtrim(config('app.url'), '/').'/app/?invite='.$token], 201);
    }

    public function reviewPlan(Request $request, int $id): JsonResponse
    {
        $this->access->requireRole('super_admin');
        $data = $request->validate(['approved' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:1000']]);
        DB::transaction(function () use ($data, $id) {
            $row = DB::table('billing_requests')->where('id', $id)->where('status', 'pending')->lockForUpdate()->first();
            abort_unless($row, 404);
            $org = DB::table('organizations')->where('id', $row->organization_id)->lockForUpdate()->first();
            if ($data['approved']) {
                $limits = match ($row->plan) {
                    'teacher_pro' => [200, 5, 1073741824],
                    'center' => [1000, 30, 10737418240],
                    'school' => [5000, 200, 53687091200],
                    default => [50, 3, 104857600],
                };
                $users = DB::table('users')->where('organization_id', $org->id)->where('active', true);
                abort_if((clone $users)->where('role', 'student')->count() > $limits[0] || (clone $users)->where('role', 'teacher')->count() > $limits[1] || DB::table('attachments')->where('organization_id', $org->id)->sum('size') > $limits[2], 422, 'Mức sử dụng hiện tại vượt giới hạn gói được chọn.');
                DB::table('organizations')->where('id', $org->id)->update(['plan' => $row->plan, 'max_students' => $limits[0], 'max_teachers' => $limits[1], 'storage_limit_bytes' => $limits[2], 'updated_at' => now()]);
            }
            DB::table('billing_requests')->where('id', $id)->update(['status' => $data['approved'] ? 'approved' : 'rejected', 'updated_at' => now()]);
            $this->access->audit('plan_reviewed', 'billing_request', $id, null, $data);
        });

        return response()->json(['message' => 'Đã xét duyệt yêu cầu.']);
    }

    public function suspend(Request $request, int $id): JsonResponse
    {
        $this->access->requireRole('super_admin');
        $data = $request->validate(['suspended' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:1000']]);
        abort_if($id === (int) $this->access->user()->organization_id, 422, 'Không thể tạm ngưng tổ chức đang quản trị nền tảng.');
        abort_unless(DB::table('organizations')->where('id', $id)->exists(), 404);
        DB::transaction(function () use ($id, $data) {
            DB::table('organizations')->where('id', $id)->update(['subscription_status' => $data['suspended'] ? 'suspended' : 'active', 'updated_at' => now()]);
            if ($data['suspended']) {
                DB::table('api_tokens')->where('organization_id', $id)->delete();
            }
            $this->access->audit('organization_suspension_changed', 'organization', $id, null, $data);
        });

        return response()->json(['message' => 'Đã cập nhật trạng thái tổ chức.']);
    }
}
