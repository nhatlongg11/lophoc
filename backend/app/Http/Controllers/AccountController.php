<?php

namespace App\Http\Controllers;

use App\LearningAccess;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function __construct(private LearningAccess $access) {}

    public function register(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'confirmed', 'min:12', 'max:128'],
            'role' => ['required', 'string', Rule::in(['student', 'teacher', 'parent'])],
            'parent_pin' => ['required_if:role,parent', 'nullable', 'digits_between:6,12'],
            'organization_id' => ['prohibited'],
            'student_id' => ['prohibited'],
            'student_ids' => ['prohibited'],
        ]);
        $user = DB::transaction(function () use ($data): User {
            $organization = DB::table('organizations')->where('id', config('eduquest.registration_organization_id'))->lockForUpdate()->first();
            abort_unless($organization && $organization->subscription_status === 'active', 422, 'Hệ thống hiện chưa nhận đăng ký. Vui lòng thử lại sau.');
            if (User::whereRaw('LOWER(email) = ?', [$data['email']])->exists()) {
                throw ValidationException::withMessages(['email' => 'Email này đã được đăng ký. Vui lòng đăng nhập hoặc đặt lại mật khẩu.']);
            }
            $role = $data['role'] === 'parent' ? 'family' : $data['role'];
            if ($role !== 'family') {
                $limit = $role === 'student' ? $organization->max_students : $organization->max_teachers;
                abort_if(User::where('organization_id', $organization->id)->where('role', $role)->where('active', true)->count() >= $limit, 422, 'Hệ thống đã đạt giới hạn tài khoản cho vai trò này. Vui lòng liên hệ quản trị viên.');
            }
            $user = User::create([
                'organization_id' => $organization->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => $role,
                'active' => true,
                'parent_pin_hash' => $role === 'family' ? Hash::make($data['parent_pin']) : null,
            ]);
            DB::table('audit_logs')->insert(['organization_id' => $organization->id, 'actor_id' => $user->id, 'action' => 'self_registered', 'entity_type' => 'user', 'entity_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

            return $user;
        });

        return response()->json(['message' => 'Đã tạo tài khoản.', 'email' => $user->email], 201);
    }

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($data);

        return response()->json(['message' => 'Nếu email đã đăng ký, hướng dẫn đặt lại mật khẩu sẽ được gửi.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'token' => ['required', 'string'], 'password' => ['required', 'confirmed', 'min:12', 'max:128']]);
        $status = Password::reset($data, function (User $user, string $password): void {
            DB::transaction(function () use ($user, $password) {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                DB::table('api_tokens')->where('user_id', $user->id)->delete();
            });
            event(new PasswordReset($user));
        });
        abort_unless($status === Password::PasswordReset, 422, 'Liên kết không hợp lệ hoặc đã hết hạn.');

        return response()->json(['message' => 'Đã đặt lại mật khẩu. Vui lòng đăng nhập.']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->attributes->get('identity_user');
        if (in_array($user->role, ['family', 'parent'], true)) {
            $this->access->requireRole('parent');
        }
        $data = $request->validate(['current_password' => ['required', 'string'], 'password' => ['required', 'string', 'confirmed', 'min:12', 'max:128'], 'parent_pin' => ['nullable', 'digits_between:6,12']]);
        abort_unless(Hash::check($data['current_password'], $user->password), 422, 'Mật khẩu hiện tại không đúng.');
        DB::transaction(function () use ($user, $data, $request) {
            $user->password = $data['password'];
            if (! empty($data['parent_pin']) && in_array($user->role, ['family', 'parent'], true)) {
                $user->parent_pin_hash = Hash::make($data['parent_pin']);
            }
            $user->save();
            $this->access->table('api_tokens')->where('user_id', $user->id)->where('id', '!=', $request->attributes->get('api_token_id'))->delete();
            $this->access->audit('password_changed', 'user', $user->id);
        });
        $request->session()->regenerate();

        return response()->json(['message' => 'Đã đổi mật khẩu và thu hồi các phiên khác.']);
    }

    public function invite(Request $request): JsonResponse
    {
        $this->access->requireRole('admin');
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'role' => ['required', Rule::in(['teacher', 'student', 'family'])], 'student_id' => ['required_if:role,family', 'nullable', $this->access->exists('users')->where('role', 'student')]]);
        $token = Str::random(64);
        $id = $this->access->table('invitations')->insertGetId([...$data, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(3), 'created_at' => now(), 'updated_at' => now()]);
        $this->access->audit('invited', 'invitation', $id, null, ['role' => $data['role']]);

        return response()->json(['url' => rtrim(config('app.url'), '/').'/app/?invite='.$token, 'expires_in_hours' => 72], 201);
    }

    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string'], 'name' => ['required', 'string', 'max:160'], 'password' => ['required', 'string', 'confirmed', 'min:12', 'max:128'], 'parent_pin' => ['nullable', 'digits_between:6,12']]);
        $user = DB::transaction(function () use ($data) {
            $invitation = DB::table('invitations')->where('token_hash', hash('sha256', $data['token']))->whereNull('accepted_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($invitation, 422, 'Lời mời không hợp lệ hoặc đã hết hạn.');
            $org = DB::table('organizations')->where('id', $invitation->organization_id)->lockForUpdate()->first();
            abort_unless($org && $org->subscription_status === 'active', 422, 'Tổ chức chưa sẵn sàng nhận tài khoản.');
            $existing = User::where('email', $invitation->email)->lockForUpdate()->first();
            abort_if($existing && ($existing->organization_id !== $org->id || $existing->role !== $invitation->role || $existing->email_verified_at), 422, 'Email này không thể nhận lời mời.');
            if ((! $existing || ! $existing->active) && in_array($invitation->role, ['student', 'teacher'], true)) {
                $limit = $invitation->role === 'student' ? $org->max_students : $org->max_teachers;
                abort_if(User::where('organization_id', $org->id)->where('role', $invitation->role)->where('active', true)->count() >= $limit, 422, 'Đã đạt giới hạn tài khoản.');
            }
            abort_if($invitation->role === 'family' && empty($data['parent_pin']), 422, 'Vui lòng tạo PIN phụ huynh gồm 6–12 chữ số.');
            $attributes = ['organization_id' => $org->id, 'name' => $data['name'], 'email' => $invitation->email, 'password' => $data['password'], 'role' => $invitation->role, 'active' => true, 'parent_pin_hash' => ! empty($data['parent_pin']) ? Hash::make($data['parent_pin']) : null];
            if ($existing) {
                $existing->update($attributes);
                $user = $existing;
                DB::table('api_tokens')->where('user_id', $user->id)->delete();
            } else {
                $user = User::create($attributes);
            }
            $user->forceFill(['email_verified_at' => now()])->save();
            if ($invitation->role === 'family') {
                abort_unless(User::where('organization_id', $org->id)->where('role', 'student')->find($invitation->student_id), 422);
                DB::table('parent_student_links')->insert(['organization_id' => $org->id, 'parent_id' => $user->id, 'student_id' => $invitation->student_id, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('invitations')->where('id', $invitation->id)->update(['accepted_at' => now()]);

            return $user;
        });

        return response()->json(['message' => 'Tài khoản đã được tạo. Vui lòng đăng nhập.', 'email' => $user->email], 201);
    }

    public function preferences(): JsonResponse
    {
        return response()->json(['email_enabled' => (bool) $this->access->table('notification_preferences')->where('user_id', $this->access->user()->id)->value('email_enabled')]);
    }

    public function savePreferences(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity_user');
        if (in_array($identity->role, ['family', 'parent'], true)) {
            $this->access->requireRole('parent');
        }
        $data = $request->validate(['email_enabled' => ['required', 'boolean']]);
        $this->access->table('notification_preferences')->updateOrInsert(['user_id' => $this->access->user()->id], [...$data, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Đã lưu tùy chọn thông báo.']);
    }
}
