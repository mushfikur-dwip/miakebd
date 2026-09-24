<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\ProfileRequest;
use App\Http\Requests\ChangeImageRequest;
use App\Http\Requests\ChangePasswordRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Models\User;

class ProfileService
{

    /**
     * @param ProfileRequest $request
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     * @throws Exception
     */
    public function update(ProfileRequest $request): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        try {
            $user               = User::find(auth()->user()->id);
            if (!blank($user)) {
                $user->name         = $request->name;
                $user->phone        = $request->get('phone');
                $user->email        = $request->get('email');
                $user->country_code = $request->get('country_code');
                $user->save();
            }

            if ($request->image) {
                $user->clearMediaCollection('profile');
                $user->addMediaFromRequest('image')->toMediaCollection('profile');
            }

            return $user;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @param ChangePasswordRequest $request
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     * @throws Exception
     */
    public function changePassword(ChangePasswordRequest $request): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        try {
            $user           = User::find(auth()->user()->id);
            $user->password = bcrypt($request->get('new_password'));
            $user->save();

            // Changing a password is how someone locks out whoever else has
            // the account - but every other token stayed valid. Only the
            // session making the change survives.
            $current        = auth()->user()->currentAccessToken();
            $currentTokenId = $current instanceof \Laravel\Sanctum\PersonalAccessToken ? $current->id : null;
            $user->tokens()->when($currentTokenId, fn($query) => $query->where('id', '!=', $currentTokenId))->delete();

            return $user;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function changeImage(ChangeImageRequest $request)
    {
        try {
            $user = User::find(auth()->user()->id);
            if ($request->image) {
                $user->clearMediaCollection('profile');
                $user->addMediaFromRequest('image')->toMediaCollection('profile');
            }
            $user->save();
            return $user;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
