<?php

namespace App\Services;

use App\Enums\Ask;
use Exception;
use Carbon\Carbon;
use App\Models\Otp;
use App\Enums\OtpType;
use App\Events\SendSmsCode;
use Illuminate\Http\Request;
use App\Events\SendEmailCode;
use Illuminate\Support\Facades\DB;
use App\Events\SendVerifyEmailCode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Dipokhalder\Settings\Facades\Settings;
use App\Http\Requests\VerifyEmailRequest;
use App\Http\Requests\VerifyPhoneRequest;
use App\Libraries\QueryExceptionLibrary;

class OtpManagerService
{

    /**
     * @throws Exception
     */
    public function otpPhone(Request $request): bool
    {
        try {
            if (env('DEMO') == "True" || env('DEMO') == "TRUE" || env('DEMO') == "true" || env('DEMO') == 1) {
                return true;
            }
            $otp = DB::table('otps')->where([
                ['phone', $request->post('phone')],
                ['code', $request->post('country_code')],
            ]);

            if ($otp->exists()) {
                $otp->delete();
            }

            $token = $this->newCode(OtpType::SMS);

            $otp = Otp::create([
                'phone' => $request->phone,
                'code' => $request->country_code,
                'token' => $token,
                'created_at' => now(),
            ]);

            if (!blank($otp)) {
                SendSmsCode::dispatch(
                    ['phone' => $request->post('phone'), 'code' => $request->post('country_code'), 'token' => $token]
                );
            }

            return true;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function resetOtpEmail(Request $request): bool
    {
        try {
            if (env('DEMO') == "True" || env('DEMO') == "TRUE" || env('DEMO') == "true" || env('DEMO') == 1) {
                return true;
            }
            $otp = DB::table('password_reset_tokens')->where([
                ['email', $request->post('email')]
            ]);

            if ($otp->exists()) {
                $otp->delete();
            }

            $token = $this->newCode(OtpType::EMAIL);

            $password_reset = DB::table('password_reset_tokens')->insert([
                'email' => $request->post('email'),
                'token' => $token,
                'created_at' => Carbon::now()
            ]);

            if (!blank($password_reset)) {
                SendEmailCode::dispatch(['email' => $request->post('email'), 'pin' => $token]);
            }

            return true;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function otpEmail(Request $request): bool
    {
        try {
            if (env('DEMO') == "True" || env('DEMO') == "TRUE" || env('DEMO') == "true" || env('DEMO') == 1) {
                return true;
            }
            $otp = DB::table('password_reset_tokens')->where([
                ['email', $request->post('email')]
            ]);

            if ($otp->exists()) {
                $otp->delete();
            }

            $token = $this->newCode(OtpType::EMAIL);

            $password_reset = DB::table('password_reset_tokens')->insert([
                'email' => $request->post('email'),
                'token' => $token,
                'created_at' => Carbon::now()
            ]);

            if (!blank($password_reset)) {
                SendVerifyEmailCode::dispatch(['email' => $request->post('email'), 'pin' => $token]);
            }

            return true;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function verifyPhone(VerifyPhoneRequest $request): bool
    {
        try {
            if (env('DEMO') == "True" || env('DEMO') == "TRUE" || env('DEMO') == "true" || env('DEMO') == 1) {
                return true;
            }

            $attemptKey = 'otp-verify|phone|' . $request->post('phone');
            $this->assertAttemptsLeft($attemptKey);

            $otp = DB::table('otps')->where([
                ['phone', $request->post('phone')],
                ['token', $request->post('token')],
            ]);
            if ($otp->exists()) {
                RateLimiter::clear($attemptKey);
                $difference = (int) Carbon::now()->diffInSeconds($otp->first()->created_at, true);
                if ($difference > (int) Settings::group('otp')->get('otp_expire_time') * 60) {
                    throw new Exception(trans('all.message.code_is_expired'), 422);
                } else {
                    DB::table('otps')->where([
                        ['phone', $request->post('phone')],
                        ['token', $request->post('token')],
                    ])->update(['is_verified' => Ask::YES]);

                    return true;
                }
            } else {
                RateLimiter::hit($attemptKey, self::ATTEMPT_WINDOW_SECONDS);
                throw new Exception(trans('all.message.code_is_invalid'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function verifyEmail(VerifyEmailRequest $request): bool
    {
        try {
            if (env('DEMO') == "True" || env('DEMO') == "TRUE" || env('DEMO') == "true" || env('DEMO') == 1) {
                return true;
            }

            $attemptKey = 'otp-verify|email|' . strtolower((string) $request->post('email'));
            $this->assertAttemptsLeft($attemptKey);

            $verify = DB::table('password_reset_tokens')->where([
                ['email', $request->post('email')],
                ['token', $request->post('token')],
            ]);
            if ($verify->exists()) {
                RateLimiter::clear($attemptKey);
                DB::table('password_reset_tokens')->where([
                    ['email', $request->post('email')],
                    ['token', $request->post('token')],
                ])->update(['is_verified' => Ask::YES]);

                return true;
            } else {
                RateLimiter::hit($attemptKey, self::ATTEMPT_WINDOW_SECONDS);
                throw new Exception(trans('all.message.code_is_invalid'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * Wrong guesses allowed per phone number / email within the window.
     *
     * The route throttles are per IP, and a code is only otp_digit_limit
     * digits (4 by default) - spread across a few hundred addresses, every
     * possible code could be tried inside one code's lifetime, which is a
     * password reset on any account, staff included. This caps guesses per
     * target, whatever the source. The counter is not reset by sending a new
     * code, or each fresh SMS would buy another round of guesses.
     */
    private const MAX_VERIFY_ATTEMPTS    = 5;
    private const ATTEMPT_WINDOW_SECONDS = 600;

    /**
     * @throws Exception
     */
    private function assertAttemptsLeft(string $key): void
    {
        if (RateLimiter::tooManyAttempts($key, self::MAX_VERIFY_ATTEMPTS)) {
            throw new Exception(trans('all.message.otp_too_many_attempts'), 422);
        }
    }

    // random_int, not rand(): rand() is a seeded Mersenne Twister, not meant
    // for secrets. Digits come from the OTP settings when this channel is the
    // configured one, as before, bounded so a bad setting cannot produce an
    // empty or absurd range.
    private function newCode(int $channel): int
    {
        $type   = Settings::group('otp')->get('otp_type');
        $digits = ($channel == $type || OtpType::BOTH == $type)
            ? (int) Settings::group('otp')->get('otp_digit_limit')
            : 4;
        $digits = max(4, min(9, $digits));

        return random_int(10 ** ($digits - 1), (10 ** $digits) - 1);
    }
}
