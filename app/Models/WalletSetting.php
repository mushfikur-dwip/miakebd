<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletSetting extends Model
{
    protected $table = 'wallet_settings';
    
    protected $fillable = [
        'wallet_status',
        'cashback_status',
        'cashback_rule',
        'cashback_type',
        'cashback_amount',
        'max_cashback_amount',
        'payment_methods',
        'process_cashback'
    ];

    protected $casts = [
        'id' => 'integer',
        'wallet_status' => 'boolean',
        'cashback_status' => 'boolean',
        'cashback_amount' => 'decimal:2',
        'max_cashback_amount' => 'decimal:2',
        'payment_methods' => 'array',
    ];
}
