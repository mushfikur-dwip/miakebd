<?php

namespace App\Http\Controllers\Auth;

use Exception;
use App\Enums\Ask;
use Carbon\Carbon;
use App\Models\User;
use App\Enums\Activity;
use Illuminate\Http\Request;
use App\Libraries\AppLibrary;
use App\Services\MenuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Services\OtpManagerService;
use App\Services\PermissionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MenuResource;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Dipokhalder\Settings\Facades\Settings;
use App\Http\Requests\SignupEmailRequest;
use App\Http\Requests\SignupPhoneRequest;
use App\Http\Requests\VerifyEmailRequest;
use App\Http\Requests\VerifyPhoneRequest;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\PermissionResource;

class ForgotPasswordController extends Controller
{

    public int $pin;
    public string $token;
    private OtpManagerService $otpManagerService;
    public PermissionService $permissionService;
    public MenuService $menuService;

    public function __construct(OtpManagerService $otpManagerService, PermissionService $permissionService, MenuService $menuService)
    {
        $this->otpManagerService = $otpManagerService;
        $this->permissionService = $permissionService;
        $this->menuService       = $menuService;
    }

    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'        => request('phone') ? ['nullable', 'string', 'email', 'max:255'] : ['required', 'string', 'email', 'max:255'],
            'phone'        => request('email') ? ['nullable', 'string', 'max:20'] : ['required', 'string', 'max:20'],
            'country_code' => request('email') ? ['nullable', 'string', 'max:10'] : ['required', 'string', 'max:10'],
        ]);

        if ($validator->fails()) {
            return new JsonResponse(['errors' => $validator->errors()], 422);
        }

        // notGuest: a number with only guest-checkout rows has no account to
        // reset, and without this it would still cost a real SMS.
        $verifyEmail = User::notGuest()->where('email', $request->post('email'))->exists();
        $verifyPhone = User::notGuest()->where([
            'phone' => $request->post('phone'),
            'country_code' => $request->post('country_code'),
        ])->exists();

        if ($verifyEmail  && $request->post('email')) {
            try {
                if (Settings::group('site')->get('site_email_verification') == Activity::ENABLE && $request->post('email')) {
                    $this->otpManagerService->resetOtpEmail($request);
                    return response(['status' => true, 'message' => trans("all.message.check_your_email_for_code")]);
                } else {
                    return response(['status' => true, 'message' => trans("all.message.user_verify_success")]);
                }
            } catch (Exception $exception) {
                return response(['status' => false, 'message' => [trans('all.message.token_created_fail')]], 422);
            }
        } else if ($verifyPhone && $request->post('phone')) {
            try {
                if (Settings::group('site')->get('site_phone_verification') == Activity::ENABLE && $request->post('country_code') && $request->post('phone')) {
                    $this->otpManagerService->otpPhone($request);
                    return response(['status' => true, 'message' => trans("all.message.check_your_phone_for_code")]);
                } else {
                    return response(['status' => true, 'message' => trans("all.message.user_verify_success")]);
                }
            } catch (Exception $exception) {
                return response(['status' => false, 'message' => [trans('all.message.token_created_fail')]], 422);
            }
        } else {
            if ($request->post('email')) {
                return new JsonResponse([
                    'errors' => ['email' => [trans('all.message.email_does_not_exist')]]
                ], 422);
            } else {
                return new JsonResponse([
                    'errors' => ['phone' => [trans('all.message.phone_does_not_exist')]]
                ], 422);
            }
        }
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'email', 'max:255'],
            'code'  => ['required'],
        ]);

        if ($validator->fails()) {
            return new JsonResponse(['errors' => $validator->errors()], 422);
        }

        $check = DB::table('password_resets')->where([
            ['email', $request->post('email')],
            ['token', $request->post('code')],
        ]);

        if ($check->exists()) {
            $difference = (int) Carbon::now()->diffInSeconds($check->first()->created_at, true);

            if ($difference > (int)Settings::group('otp')->get('otp_expire_time') * 60) {
                return new JsonResponse([
                    'errors' => ['code' => [trans('all.message.code_is_expired')]]
                ], 400);
            }

            $check->delete();

            return new JsonResponse([
                'message' => trans('all.message.you_can_reset_your_password')
            ], 200);
        } else {
            return new JsonResponse([
                'errors' => ['code' => [trans('all.message.code_is_invalid')]]
            ], 400);
        }
    }

    public function otpPhone(
        SignupPhoneRequest $request
    ): \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            $this->otpManagerService->otpPhone($request);
            return response(['status' => true, 'message' => trans("all.message.check_your_phone_for_code")]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function otpEmail(
        SignupEmailRequest $request
    ): \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            $this->otpManagerService->resetOtpEmail($request);
            return response(['status' => true, 'message' => trans("all.message.check_your_email_for_code")]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function verifyPhone(
        VerifyPhoneRequest $request
    ): JsonResponse {
        try {
            $this->otpManagerService->verifyPhone($request);
            return new JsonResponse([
                'status'  => true,
                'message' => trans('all.message.otp_verify_success')
            ], 200);
        } catch (Exception $exception) {
            return new JsonResponse(['status'  => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function verifyEmail(
        VerifyEmailRequest $request
    ): JsonResponse {
        try {
            $this->otpManagerService->verifyEmail($request);
            return new JsonResponse([
                'status'  => true,
                'message' => trans('all.message.otp_verify_success')
            ], 200);
        } catch (Exception $exception) {
            return new JsonResponse(['status'  => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'                 => request('phone') ? 'nullable|string|email|max:255' : 'required|string|email|max:255',
            'phone'                 => request('email') ? 'nullable|string|max:20' : 'required|string|max:20',
            'country_code'          => request('email') ? 'nullable|string|max:10' : 'required|string|max:10',
            'password'              => 'required|string|min:6|confirmed',
            'password_confirmation' => 'required|string|min:6',
        ]);


        if ($validator->fails()) {
            return new JsonResponse(['errors' => $validator->errors()], 422);
        }

        // Nothing here verified anything before this gate: posting a phone
        // number and a new password was enough to take over an account and lock
        // its owner out. A verified, unexpired code is now required, and it is
        // consumed on success so the same SMS or email cannot be replayed.
        //
        // The channel is decided ONCE, here, and the account reset below is
        // resolved from that same identifier. Choosing the credential and the
        // target independently let a caller verify a code for an email nobody
        // owns and have the reset fall through to the account behind an
        // unrelated phone number — the validator permits both fields together.
        $usesEmail = !blank($request->post('email'));

        if ($usesEmail) {
            $consumeQuery = DB::table('password_reset_tokens')->where('email', $request->post('email'));
            $notVerified  = trans('all.message.email_not_verified');
        } else {
            // The otps table has no id column, so rows are addressed by
            // phone + code (code holds the country code).
            $consumeQuery = DB::table('otps')->where([
                ['phone', $request->post('phone')],
                ['code', $request->post('country_code')],
            ]);
            $notVerified = trans('all.message.phone_not_verified');
        }

        $verified = (clone $consumeQuery)->where('is_verified', Ask::YES)->first();

        if (blank($verified)) {
            return new JsonResponse(['errors' => ['message' => [$notVerified]]], 422);
        }

        $expireMinutes = (int) Settings::group('otp')->get('otp_expire_time');

        if ($expireMinutes > 0 && (int) Carbon::now()->diffInSeconds($verified->created_at, true) > $expireMinutes * 60) {
            (clone $consumeQuery)->delete();

            return new JsonResponse([
                'errors' => ['message' => [trans('all.message.code_is_expired')]]
            ], 422);
        }

        // Resolved from the SAME identifier the code was verified against.
        // notGuest: guest-checkout rows share phone numbers with real accounts
        // and would otherwise absorb the reset.
        $user = $usesEmail
            ? User::notGuest()->where('email', $request->post('email'))->first()
            : User::notGuest()->where([
                'phone' => $request->post('phone'),
                'country_code' => $request->post('country_code'),
            ])->first();

        if ($user) {
            $user->update([
                'password' => Hash::make($request->post('password'))
            ]);

            (clone $consumeQuery)->delete();

            // Any session or API token issued before the reset belongs to
            // whoever held the old password. Revoke them all, then mint one.
            $user->tokens()->delete();

            Auth::guard('web')->loginUsingId($user->id);
            $this->token = $user->createToken('auth_token')->plainTextToken;
            $permission        = PermissionResource::collection($this->permissionService->permission($user->roles[0]));
            $defaultPermission = AppLibrary::defaultPermission($permission);
            return new JsonResponse([
                'status'            => true,
                'message'           => trans("all.message.reset_successfully"),
                'token'             => $this->token,
                'user'              => new UserResource($user),
                'menu'              => MenuResource::collection(collect($this->menuService->menu($user->roles[0]))),
                'permission'        => $permission,
                'defaultPermission' => $defaultPermission,
            ], 201);
        } else {
            return new JsonResponse([
                'errors' => ['message' => [trans('all.message.user_does_not_exist')]]
            ], 422);
        }
    }
}
