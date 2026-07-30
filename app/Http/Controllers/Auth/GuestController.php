<?php

namespace App\Http\Controllers\Auth;

use Exception;
use App\Enums\Ask;
use Carbon\Carbon;
use App\Models\User;
use Illuminate\Support\Str;
use App\Libraries\AppLibrary;
use App\Services\MenuService;
use App\Enums\Role as EnumRole;
use Illuminate\Http\JsonResponse;
use App\Services\OtpManagerService;
use App\Services\PermissionService;
use App\Services\GuestMergeService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MenuResource;
use App\Http\Resources\UserResource;
use App\Http\Requests\GuestStartRequest;
use App\Http\Requests\SignupPhoneRequest;
use App\Http\Requests\VerifyPhoneRequest;
use App\Http\Resources\PermissionResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Guest checkout.
 *
 * The routes sit inside the api.php `auth` group, so they carry its installed /
 * apiKey / localization middleware and live under /api/auth/guest/*:
 *
 *   1. POST /api/auth/guest/start    creates a user with is_guest = YES and
 *                                    returns a Sanctum token. The frontend then
 *                                    calls the existing order endpoint with that
 *                                    token, so order creation stays untouched.
 *   2. POST /api/auth/guest/send-otp SMS code, offered on the confirmation screen.
 *   3. POST /api/auth/guest/claim    verifies the code, upgrades the guest to a
 *                                    real customer and merges every past guest
 *                                    order on that number.
 *
 * A new guest user is created per checkout rather than reusing one keyed on the
 * phone number. Reuse would mean anyone who types a customer's number into the
 * checkout form receives a token for that account and can read its order
 * history — phone numbers are not secret. Accounts merge only after an OTP
 * proves ownership.
 */
class GuestController extends Controller
{
    private OtpManagerService $otpManagerService;
    private PermissionService $permissionService;
    private MenuService $menuService;
    private GuestMergeService $guestMergeService;

    public function __construct(
        OtpManagerService $otpManagerService,
        PermissionService $permissionService,
        MenuService $menuService,
        GuestMergeService $guestMergeService
    ) {
        $this->otpManagerService = $otpManagerService;
        $this->permissionService = $permissionService;
        $this->menuService       = $menuService;
        $this->guestMergeService = $guestMergeService;
    }

    /**
     * Creates a guest user and returns a token the frontend can use to place an
     * order through the normal order endpoint.
     */
    public function start(GuestStartRequest $request): JsonResponse
    {
        // input(), not post(): on a JSON request the two read different bags.
        // GuestStartRequest::prepareForValidation() normalises the number via
        // merge(), which writes to the JSON bag — post() reads the form bag and
        // so returned the raw value, letting "880"/"01712…" slip past the check
        // below that refuses a guest session for a registered number.
        $phone       = trim((string) $request->input('phone'));
        $countryCode = trim((string) $request->input('country_code'));

        // If a real account already owns this number, do not hand out a guest
        // session for it — that would be a way around the login.
        $registered = User::notGuest()->where([
            'phone'        => $phone,
            'country_code' => $countryCode,
        ])->first();

        if ($registered) {
            return new JsonResponse([
                'status'         => false,
                'account_exists' => true,
                'message'        => 'An account already exists with this phone number. Please log in to continue.',
            ], 409);
        }

        try {
            $user = DB::transaction(function () use ($request, $phone, $countryCode) {
                $user = User::create([
                    'name'              => $request->input('name'),
                    'username'          => AppLibrary::username($request->input('name')),
                    // Never written to the guest row even when supplied: two
                    // users sharing an email make the login lookups ambiguous,
                    // and the order/address tables carry their own email field.
                    'email'             => null,
                    'phone'             => $phone,
                    'country_code'      => $countryCode,
                    'email_verified_at' => Carbon::now()->getTimestamp(),
                    'is_guest'          => Ask::YES,
                    // The guest never sees or uses this. A real password is set
                    // later, if they choose, when they claim the account.
                    'password'          => Hash::make(Str::random(40)),
                ]);

                $user->assignRole(EnumRole::CUSTOMER);

                return $user;
            });
        } catch (Exception $exception) {
            Log::error('Guest start failed: ' . $exception->getMessage());

            return new JsonResponse([
                'status'  => false,
                'message' => 'Could not start guest checkout. Please try again.',
            ], 422);
        }

        return $this->tokenResponse($user, 'guest_token', 'Guest checkout started.', 201);
    }

    /**
     * Sends an OTP so the customer can turn their guest orders into a real
     * account. The number must already have at least one guest record —
     * without that check this endpoint is a free SMS gateway for any number.
     */
    public function sendOtp(SignupPhoneRequest $request): JsonResponse
    {
        $hasGuestRecord = User::where([
            'phone'        => trim((string) $request->input('phone')),
            'country_code' => trim((string) $request->input('country_code')),
        ])->where('is_guest', Ask::YES)->exists();

        if (!$hasGuestRecord) {
            return new JsonResponse([
                'status'  => false,
                'message' => 'No guest order was found for this phone number.',
            ], 404);
        }

        try {
            $this->otpManagerService->otpPhone($request);
        } catch (Exception $exception) {
            return new JsonResponse(['status' => false, 'message' => $exception->getMessage()], 422);
        }

        return new JsonResponse([
            'status'  => true,
            'message' => trans('all.message.check_your_phone_for_code'),
        ]);
    }

    /**
     * Verifies the OTP, upgrades the guest to a full customer, merges every
     * other guest order placed on the same number, and returns a real token.
     *
     * An optional `password` lets the customer set one straight away. Without
     * it they can still use the forgot-password flow later.
     */
    public function claim(VerifyPhoneRequest $request): JsonResponse
    {
        $phone       = trim((string) $request->input('phone'));
        $countryCode = trim((string) $request->input('country_code'));
        $code        = (string) $request->input('token');
        $password    = $request->input('password');

        // VerifyPhoneRequest covers phone/country_code/token only.
        if (!blank($password) && Str::length($password) < 6) {
            return new JsonResponse([
                'status'  => false,
                'message' => 'The password must be at least 6 characters.',
            ], 422);
        }

        // verifyPhone() matches otps on phone + token and deliberately ignores
        // the `code` column, which holds the country code the SMS was actually
        // delivered to. Bind it here before calling: without this, a code sent
        // to the same local digits under a DIFFERENT country code verifies, and
        // the target below is resolved from the country code in this request —
        // so whoever received that other SMS takes over the account.
        $ownsCode = DB::table('otps')->where([
            ['phone', $phone],
            ['code', $countryCode],
            ['token', $code],
        ])->exists();

        if (!$ownsCode) {
            return new JsonResponse([
                'status' => false,
                'message' => trans('all.message.code_is_invalid'),
            ], 422);
        }

        // This is the step the old login-verify endpoint skipped. No token is
        // issued anywhere below unless this passes.
        try {
            $this->otpManagerService->verifyPhone($request);
        } catch (Exception $exception) {
            return new JsonResponse(['status' => false, 'message' => $exception->getMessage()], 422);
        }

        $registered = User::notGuest()->where([
            'phone'        => $phone,
            'country_code' => $countryCode,
        ])->first();

        $guests = User::where([
            'phone'        => $phone,
            'country_code' => $countryCode,
        ])->where('is_guest', Ask::YES)->orderByDesc('id')->get();

        if ($registered === null && $guests->isEmpty()) {
            return new JsonResponse([
                'status'  => false,
                'message' => 'No account or guest order was found for this phone number.',
            ], 404);
        }

        // Prefer an existing real account, so a customer who already signed up
        // absorbs their guest orders rather than getting a second account.
        $target = $registered ?: $guests->first();

        $mergedOrders = 0;

        try {
            DB::transaction(function () use ($guests, $target, $request, $password, $phone, $code, &$mergedOrders) {
                // Consumed INSIDE the transaction. Deleting it afterwards meant
                // a failed merge left a verified row behind, and a verified row
                // is a standing credential — reset-password accepts one without
                // re-checking the token.
                DB::table('otps')->where([
                    ['phone', $phone],
                    ['token', $code],
                ])->delete();

                $mergedOrders = $this->guestMergeService->merge($target, $guests);

                if ($target->is_guest == Ask::YES) {
                    $target->is_guest          = Ask::NO;
                    $target->email_verified_at = Carbon::now()->getTimestamp();
                }

                if (!blank($password)) {
                    $target->password = Hash::make($password);
                }

                // VerifyPhoneRequest does not cover `name`, and this writes
                // straight to the user row — bound here rather than trusting it.
                if (!blank($request->input('name'))) {
                    $target->name = mb_substr(trim((string) $request->input('name')), 0, 120);
                }

                $target->save();

                if (!$target->hasRole(EnumRole::CUSTOMER)) {
                    $target->assignRole(EnumRole::CUSTOMER);
                }
            });
        } catch (Exception $exception) {
            Log::error('Guest claim failed: ' . $exception->getMessage());

            return new JsonResponse([
                'status'  => false,
                'message' => 'Could not complete account creation. Please try again.',
            ], 422);
        }

        // Every guest_token minted before this upgrade was handed out with no
        // verification at all. The account now has a password and full customer
        // access, so those tokens must not survive the transition.
        $target->tokens()->delete();

        $target->refresh();

        return $this->tokenResponse(
            $target,
            'auth_token',
            trans('all.message.register_successfully'),
            201,
            ['merged_orders' => $mergedOrders]
        );
    }

    /**
     * Builds the same response shape as SignupController::signupLoginVerify(),
     * so the frontend can reuse its existing login handler unchanged.
     */
    private function tokenResponse(User $user, string $tokenName, string $message, int $status, array $extra = []): JsonResponse
    {
        Auth::guard('web')->loginUsingId($user->id);

        // load(), not loadMissing(): assignRole() ran moments ago and the
        // relation may be cached from before it.
        $user->load('roles');

        if (!isset($user->roles[0])) {
            return new JsonResponse([
                'status'  => false,
                'message' => trans('all.message.role_exist'),
            ], 400);
        }

        $token      = $user->createToken($tokenName)->plainTextToken;
        $permission = PermissionResource::collection($this->permissionService->permission($user->roles[0]));

        return new JsonResponse(array_merge([
            'status'            => true,
            'message'           => $message,
            'token'             => $token,
            'user'              => new UserResource($user),
            'menu'              => MenuResource::collection(collect($this->menuService->menu($user->roles[0]))),
            'permission'        => $permission,
            'defaultPermission' => AppLibrary::defaultPermission($permission),
        ], $extra), $status);
    }
}
