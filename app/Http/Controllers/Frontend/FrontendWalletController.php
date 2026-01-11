<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Libraries\AppLibrary;
use App\Models\Transaction;
use Illuminate\Http\Request;

class FrontendWalletController extends Controller
{
    /**
     * Get the authenticated user's wallet balance
     */
    public function balance(Request $request)
    {
        $user = $request->user();
        
        return response()->json([
            'data' => [
                'balance' => $user->balance ?? 0,
                'currency_balance' => AppLibrary::currencyAmountFormat($user->balance ?? 0)
            ]
        ]);
    }

    /**
     * Get the authenticated user's wallet transaction history
     */
    public function transactions(Request $request)
    {
        $user = $request->user();
        
        $transactions = Transaction::where('user_id', $user->id)
            ->with(['user:id,name', 'admin:id,name'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);
        
        return TransactionResource::collection($transactions);
    }
}
