<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WalletSetting;
use Illuminate\Http\Request;
use Exception;

class WalletSettingController extends Controller
{
    public function show()
    {
        try {
            $walletSetting = WalletSetting::first();
            
            if (!$walletSetting) {
                $walletSetting = WalletSetting::create([
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
