<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ApiAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = app()->environment('testing') && $request->bearerToken() ? $request->bearerToken() : $request->session()->get('eduquest_token');
        if (! $token) {
            return response()->json(['message' => 'Vui lòng đăng nhập để tiếp tục.'], 401);
        }
        $row = DB::table('api_tokens')->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
        $user = $row ? User::find($row->user_id) : null;
        if (! $user || ! $user->active || $row->organization_id !== $user->organization_id) {
            return response()->json(['message' => 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.'], 401);
        }
        $organization = DB::table('organizations')->where('id', $user->organization_id)->first();
        if (! $organization || $organization->subscription_status === 'suspended') {
            return response()->json(['message' => 'Tổ chức đang tạm ngưng hoạt động.'], 403);
        }
        $request->attributes->set('identity_user', $user);
        $request->attributes->set('account_session', $row);
        if (in_array($user->role, ['family', 'parent'], true)) {
            if ($row->mode === 'student' && $row->profile_student_id) {
                $linked = DB::table('parent_student_links')->where('organization_id', $user->organization_id)->where('parent_id', $user->id)->where('student_id', $row->profile_student_id)->exists();
                $student = $linked ? User::where('organization_id', $user->organization_id)->find($row->profile_student_id) : null;
                if (! $student || ! $student->active || $student->role !== 'student') {
                    return response()->json(['message' => 'Hồ sơ học sinh không còn khả dụng.'], 403);
                }
                $user = $student;
            } elseif ($row->mode === 'parent' && $row->parent_verified_until && now()->lessThan($row->parent_verified_until)) {
                $user = clone $user;
                $user->role = 'parent';
            } else {
                $user = clone $user;
                $user->role = 'family';
            }
        }
        Auth::setUser($user);
        $request->attributes->set('api_token_id', $row->id);

        return $next($request);
    }
}
