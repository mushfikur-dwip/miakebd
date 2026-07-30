<?php

namespace App\Http\Controllers\Auth;

use Exception;
use App\Enums\Ask;
use Carbon\Carbon;
use App\Models\User;
use App\Enums\Activity;
use App\Libraries\AppLibrary;
use App\Services\MenuService;
use App\Enums\Role as EnumRole;
use Illuminate\Http\JsonResponse;
use App\Services\OtpManagerService;
use App\Services\PermissionService;
use App\Services\GuestMergeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\SignupRequest;
use App\Http\Resources\MenuResource;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Dipokhalder\Settings\Facades\Settings;
use App\Http\Requests\SignupEmailRequest;
use App\Http\Requests\SignupPhoneRequest;
use App\Http\Requests\VerifyEmailRequest;
use App\Http\Requests\VerifyPhoneRequest;
use App\Http\Resources\PermissionResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SignupController extends Controller
{

    private OtpManagerService $otpManagerService;
    public string $token;
    public PermissionService $permissionService;
    public MenuService $menuService;
    public GuestMergeService $guestMergeService;

    public function __construct(OtpManagerService $otpManagerService, PermissionService $permissionService, MenuService $menuService, GuestMergeService $guestMergeService)
    {
        $this->otpManagerService = $otpManagerService;
        $this->permissionService = $permissionService;
        $this->menuService = $menuService;
        $this->guestMergeService = $guestMergeService;
    }

    public function otpPhone(
        SignupPhoneRequest $request
    ): \Illuminate\Http\Response|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            $this->otpManagerService->otpPhone($request);
            return response(['status' => true, 'message' => trans("all.message.check_your_phone_for_code")]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function otpEmail(
        SignupEmailRequest $request
    ): \Illuminate\Http\Response|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            $this->otpManagerService->otpEmail($request);
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
                'status' => true,
                'message' => trans('all.message.otp_verify_success')
            ]);
        } catch (Exception $exception) {
            return new JsonResponse(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function verifyEmail(
        VerifyEmailRequest $request
    ): JsonResponse {
        try {
            $this->otpManagerService->verifyEmail($request);
            return new JsonResponse([
                'status' => true,
                'message' => trans('all.message.otp_verify_success')
            ]);
        } catch (Exception $exception) {
            return new JsonResponse(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function validateRegister(SignupRequest $request)
    {
        return response(['status' => true, 'message' => trans('all.message.the_form_is_valid')]);
    }
    public function register(SignupRequest $request)
    {

        // (int) cast: the settings store returns this value as a string, and a
        // strict === against the integer constant silently skipped the whole
        // OTP gate — which now also guards the guest-account merge below.
        if (
            !blank($request->phone) && (int) Settings::group('site')->get('site_phone_verification') === Ask::YES &&
            (env('DEMO') !== "true" && env('DEMO') !== "TRUE" && env('DEMO') !== "True" &&
                env('DEMO') !== true && env('DEMO') !== TRUE && env('DEMO') !== True && env('DEMO') !== '1' && env('DEMO') !== 1)
        ) {

            // Scoped by country code as well as phone. The merge below hands
            // over every guest order on (phone, country_code), so a gate that
            // matched on the local digits alone let a code verified under one
            // country code unlock another country code's order history.
            $otp = DB::table('otps')->where([
                ['phone', $request->post('phone')],
                ['code', $request->post('country_code')],
            ]);
            $otpCheck = $otp->first();
            // The row is deliberately left in place. signupLoginVerify() runs
            // straight after this call and needs the verified code to prove the
            // number belongs to the caller before it issues a token; it deletes
            // the row itself once consumed.
            if (!$otp->exists() || $otpCheck->is_verified != Ask::YES) {
                return response(['status' => true, 'message' => trans('all.message.phone_not_verified')]);
            }
        } else if (
            !blank($request->email) && (int) Settings::group('site')->get('site_email_verification') === Ask::YES &&
            (env('DEMO') !== "true" && env('DEMO') !== "TRUE" && env('DEMO') !== "True" &&
                env('DEMO') !== true && env('DEMO') !== TRUE && env('DEMO') !== True && env('DEMO') !== '1' && env('DEMO') !== 1)
        ) {

            $otp = DB::table('password_reset_tokens')->where([
                ['email', $request->post('email')],
            ]);
            $otpCheck = $otp->first();
            // Left in place for signupLoginVerify(), same reason as the phone branch.
            if (!$otp->exists() || $otpCheck->is_verified != Ask::YES) {
                return response(['status' => true, 'message' => trans('all.message.email_not_verified')]);
            }
        }


        // Upgrade an existing guest record rather than creating a second user,
        // so the customer keeps the orders they placed before signing up.
        // SignupRequest scopes its uniqueness checks to is_guest = NO, so a
        // number that only has guest records is not treated as taken.
        if (!blank($request->post('phone')) && !blank($request->post('country_code'))) {
            $guestUsers = User::where([
                'phone' => $request->post('phone'),
                'country_code' => $request->post('country_code'),
            ])->where('is_guest', Ask::YES)->orderByDesc('id')->get();

            if ($guestUsers->isNotEmpty()) {
                $primary = $guestUsers->first();

                DB::transaction(function () use ($guestUsers, $primary, $request) {
                    $this->guestMergeService->merge($primary, $guestUsers);

                    $primary->name = $request->post('name');
                    $primary->email = $request->post('email');
                    $primary->password = Hash::make($request->post('password'));
                    $primary->is_guest = Ask::NO;
                    $primary->email_verified_at = Carbon::now()->getTimestamp();
                    $primary->save();
                });

                if (!$primary->hasRole(EnumRole::CUSTOMER)) {
                    $primary->assignRole(EnumRole::CUSTOMER);
                }

                // Guest tokens were issued with no verification. The row now
                // has a password and a full customer account behind it, so none
                // of them may survive the upgrade.
                $primary->tokens()->delete();

                return response(['status' => true, 'message' => trans('all.message.register_successfully')]);
            }
        }

        $user = User::create([
            'name' => $request->post('name'),
            'username' => AppLibrary::username($request->post('name')),
            'email' => $request->post('email'),
            'phone' => $request->post('phone'),
            'country_code' => $request->post('country_code'),
            'email_verified_at' => Carbon::now()->getTimestamp(),
            'is_guest' => Ask::NO,
            'password' => Hash::make($request->post('password'))
        ]);
        $user->assignRole(EnumRole::CUSTOMER);
        if ($user) {
            return response(['status' => true, 'message' => trans('all.message.register_successfully')]);
        } else {
            return response(['status' => false, 'message' => trans('all.message.register_not_completed')], 422);
        }
    }

    public function signupLoginVerify(Request $request)
    {
        try {
            $user = null;

            if (isset($request->phone) && !blank($request->phone)) {

                // A token is only issued when the otps table holds a verified,
                // unexpired code for this number — the code the customer just
                // entered on the verify-phone step. Without this the endpoint
                // hands out an auth token to anyone who knows a phone number.
                // The otps table has no id column, so rows are addressed by
                // phone + code (code holds the country code). otpPhone() clears
                // any earlier row for the pair before inserting, so this is
                // always at most one row.
                $otpQuery = DB::table('otps')->where([
                    ['phone', $request->post('phone')],
                    ['code', $request->post('country_code')],
                ]);

                $otp = (clone $otpQuery)->where('is_verified', Ask::YES)->first();

                if (blank($otp)) {
                    return new JsonResponse([
                        'status' => false,
                        'message' => trans('all.message.phone_not_verified'),
                    ], 422);
                }

                $expireMinutes = (int) Settings::group('otp')->get('otp_expire_time');
                $age = (int) Carbon::now()->diffInSeconds($otp->created_at, true);

                if ($expireMinutes > 0 && $age > $expireMinutes * 60) {
                    (clone $otpQuery)->delete();

                    return new JsonResponse([
                        'status' => false,
                        'message' => trans('all.message.code_is_expired'),
                    ], 422);
                }

                // notGuest: leftover guest rows share this number and carry
                // lower ids, so an unscoped lookup would issue the token for a
                // guest account instead of the one just registered.
                $user = User::notGuest()->where([
                    'phone' => $request->phone,
                    'country_code' => $request->country_code,
                ])->first();

                // One code, one login. Prevents replaying the same SMS.
                (clone $otpQuery)->delete();

            } else {

                $verified = DB::table('password_reset_tokens')->where([
                    ['email', $request->post('email')],
                    ['is_verified', Ask::YES],
                ])->first();

                if (blank($verified)) {
                    return new JsonResponse([
                        'status' => false,
                        'message' => trans('all.message.email_not_verified'),
                    ], 422);
                }

                // Same expiry rule as the phone branch. Without it a row
                // verified weeks ago still issues a full auth token: these rows
                // are keyed on the email address and survive until consumed, so
                // every abandoned signup left a permanent login credential.
                $emailExpireMinutes = (int) Settings::group('otp')->get('otp_expire_time');
                $emailAge = (int) Carbon::now()->diffInSeconds($verified->created_at, true);

                if ($emailExpireMinutes > 0 && $emailAge > $emailExpireMinutes * 60) {
                    DB::table('password_reset_tokens')->where('email', $request->post('email'))->delete();

                    return new JsonResponse([
                        'status' => false,
                        'message' => trans('all.message.code_is_expired'),
                    ], 422);
                }

                $user = User::notGuest()->where(['email' => $request->email])->first();

                DB::table('password_reset_tokens')->where('email', $request->post('email'))->delete();
            }

            if ($user) {
                Auth::guard('web')->loginUsingId($user->id);
                $this->token = $user->createToken('auth_token')->plainTextToken;
                $permission = PermissionResource::collection($this->permissionService->permission($user->roles[0]));
                $defaultPermission = AppLibrary::defaultPermission($permission);
                return new JsonResponse([
                    'status' => true,
                    'message' => trans('all.message.register_successfully'),
                    'token' => $this->token,
                    'user' => new UserResource($user),
                    'menu' => MenuResource::collection(collect($this->menuService->menu($user->roles[0]))),
                    'permission' => $permission,
                    'defaultPermission' => $defaultPermission,
                ], 201);
            } else {
                return response(['status' => false, 'message' => trans('all.message.register_not_completed')], 422);
            }
        } catch (Exception $exception) {
            return new JsonResponse(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
