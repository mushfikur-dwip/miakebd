<?php

namespace App\Http\Controllers\Admin;

use App\Models\WalletSetting;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Exception;

/**
 * The /api/admin group only requires auth:sanctum, and every customer holds a
 * sanctum token. Without a permission check on this controller any logged-in
 * shopper could PATCH the cashback settings - set cashback_status true,
 * cashback_type percentage, cashback_amount 100 - and then mint wallet balance
 * on their own orders. These settings are edited from Settings → Wallet, which
 * the router guards with permissionUrl "settings".
 */
class WalletSettingController extends AdminController implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:settings', only: ['show', 'update']),
        ];
    }

    public function show()
    {
        try {
            $walletSetting = WalletSetting::first();
            
            if (!$walletSetting) {
                $walletSetting = WalletSetting::create([
                    'wallet_status' => true,
                    'cashback_status' => false,
                    'cashback_rule' => 'cart_wise',
                    'cashback_type' => 'percentage',
                    'cashback_amount' => 0,
                    'max_cashback_amount' => null,
                    'payment_methods' => [],
                    'process_cashback' => 'delivered'
                ]);
            }
            
            return response()->json(['data' => $walletSetting]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(Request $request)
    {
        $request->validate([
            'wallet_status' => 'required|boolean',
            'cashback_status' => 'required|boolean',
            'cashback_rule' => 'required|string',
            'cashback_type' => 'required|in:percentage,fixed',
            'cashback_amount' => 'required|numeric|min:0',
            'max_cashback_amount' => 'nullable|numeric|min:0',
            'payment_methods' => 'nullable|array',
            'process_cashback' => 'required|string'
        ]);

        try {
            $walletSetting = WalletSetting::first();
            
            if (!$walletSetting) {
                $walletSetting = WalletSetting::create($request->all());
            } else {
                $walletSetting->update($request->all());
            }
            
            return response()->json([
                'status' => true, 
                'message' => 'Wallet settings updated successfully',
                'data' => $walletSetting
            ]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
