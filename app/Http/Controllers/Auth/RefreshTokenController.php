<?php

namespace App\Http\Controllers\Auth;


use Exception;
use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\TokenStoreRequest;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Http\JsonResponse;


class RefreshTokenController extends Controller
{
    /**
     * Swaps a token for a fresh one.
     *
     * It used to mint a new token and leave the old one alive, and it never
     * looked at the token's age. So one leaked token could be turned into any
     * number of spares - each surviving the original being revoked - and once
     * SANCTUM_TOKEN_EXPIRATION is set, an expired token would still have
     * bought a brand-new one. Now it is a rotation: the presented token must be
     * live, belong to an active account, and is deleted as the new one is
     * issued.
     */
    public function refreshToken(TokenStoreRequest $request)
    {
        try {
            $token = PersonalAccessToken::findToken((string) $request->token);
            $user  = $token?->tokenable;

            if (!$token || !$user || $this->expired($token) || (int) $user->status === Status::INACTIVE) {
                throw new Exception('invalid token');
            }

            $newToken = $user->createToken($token->name ?: 'auth_token')->plainTextToken;
            $token->delete();

            return new JsonResponse([
                'token' => $newToken,
            ], 201);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => trans('all.message.token_is_invalid')], 422);
        }
    }

    private function expired(PersonalAccessToken $token): bool
    {
        if ($token->expires_at && $token->expires_at->isPast()) {
            return true;
        }

        $minutes = config('sanctum.expiration');

        return $minutes && $token->created_at && $token->created_at->lte(now()->subMinutes((int) $minutes));
    }
}
